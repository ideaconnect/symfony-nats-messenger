<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use Amp\Future;
use IDCT\NATS\Connection\Enum\ConnectionState;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Core\NatsMessage;
use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\JetStream\Configuration\ConsumerConfiguration;
use IDCT\NATS\JetStream\Configuration\StreamConfiguration;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NATS\JetStream\Models\ConsumerInfo;
use IDCT\NATS\JetStream\Models\StreamInfo;
use IDCT\NATS\Protocol\ServerInfo;
use IDCT\NatsMessenger\Stamp\DeduplicationIdStamp;
use IDCT\NatsMessenger\Stamp\NatsReceivedSubjectStamp;
use IDCT\NatsMessenger\Stamp\NatsSubjectStamp;
use IDCT\NatsMessenger\Tests\Support\JetStreamInjectedNatsTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Per-message subjects: the topic is the default publish subject, a NatsSubjectStamp names another, and
 * additional_subjects adds the patterns the stream and the durable consumer cover next to the topic.
 */
final class SubjectRoutingTest extends TestCase
{
    private const DSN = 'nats://localhost:4222/test-stream/test-topic';

    private const EXTRA = 'test-topic.updated.>';

    public function testSendPublishesToTheStampedSubject(): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic.updated.42');

        $transport->send(new Envelope(new \stdClass(), [new NatsSubjectStamp('test-topic.updated.42')]));
    }

    public function testSendPublishesToTheTopicWithoutAStamp(): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic');

        $transport->send(new Envelope(new \stdClass()));
    }

    public function testSendAcceptsAStampNamingTheTopicWithoutAdditionalSubjects(): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic', []);

        $transport->send(new Envelope(new \stdClass(), [new NatsSubjectStamp('test-topic')]));
    }

    #[DataProvider('subjectsTheConsumerReadsProvider')]
    public function testSendPublishesToAStampedSubjectTheConsumerReads(string $additionalSubjects, string $subject): void
    {
        $transport = $this->transportExpectingPublishTo($subject, ['additional_subjects' => $additionalSubjects]);

        $transport->send(new Envelope(new \stdClass(), [new NatsSubjectStamp($subject)]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function subjectsTheConsumerReadsProvider(): iterable
    {
        yield '> for one token' => ['test-topic.updated.>', 'test-topic.updated.42'];
        yield '> for several tokens' => ['test-topic.updated.>', 'test-topic.updated.42.price'];
        yield '* for one token' => ['test-topic.*.updated', 'test-topic.42.updated'];
    }

    #[DataProvider('subjectsTheConsumerDoesNotReadProvider')]
    public function testSendPublishesToTheTopicWhenTheConsumerDoesNotReadTheStampedSubject(?string $additionalSubjects, string $subject): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic', ['additional_subjects' => $additionalSubjects]);

        $transport->send(new Envelope(new \stdClass(), [new NatsSubjectStamp($subject)]));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function subjectsTheConsumerDoesNotReadProvider(): iterable
    {
        yield 'no pattern matches' => [self::EXTRA, 'other-topic.updated.1'];
        yield 'no additional_subjects' => [null, 'test-topic.updated.42'];
        yield '> stands for at least one token' => ['test-topic.updated.>', 'test-topic.updated'];
        yield '* stands for one token, not two' => ['test-topic.updated.*', 'test-topic.updated.42.price'];
    }

    /**
     * Symfony's retry listener re-sends the envelope the worker received, with its ReceivedStamp and the
     * subject stamp get() added, plus a retry count.
     */
    public function testSendPublishesARetryToTheSubjectTheMessageArrivedOn(): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic.updated.42');

        $transport->send(new Envelope(new \stdClass(), [
            new ReceivedStamp('test'),
            new NatsReceivedSubjectStamp('test-topic.updated.42'),
            new RedeliveryStamp(1),
        ]));
    }

    public function testSendIgnoresTheReceivedSubjectWhenTheEnvelopeIsNotARetry(): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic');

        $transport->send(new Envelope(new \stdClass(), [
            new ReceivedStamp('test'),
            new NatsReceivedSubjectStamp('test-topic.updated.42'),
        ]));
    }

    /**
     * A retry count alone does not make a retry: no worker received this envelope (an application stamped a
     * fresh dispatch itself), so it is published like one.
     */
    public function testSendIgnoresTheReceivedSubjectWithoutAReceivedStamp(): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic');

        $transport->send(new Envelope(new \stdClass(), [
            new NatsReceivedSubjectStamp('test-topic.updated.42'),
            new RedeliveryStamp(1),
        ]));
    }

    /**
     * The envelope Symfony's failure listener sends: the one the worker received, its retries used up, with
     * the failure stamp and a RedeliveryStamp(0) appended. The received subject belongs to the transport the
     * message failed on; the failure transport publishes to its own topic.
     */
    public function testSendPublishesAFailureTransportCopyToTheTopic(): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic');

        $transport->send(new Envelope(new \stdClass(), [
            new ReceivedStamp('source'),
            new NatsReceivedSubjectStamp('source-topic.updated.42'),
            new RedeliveryStamp(3),
            new SentToFailureTransportStamp('source'),
            new DelayStamp(0),
            new RedeliveryStamp(0),
        ]));
    }

    /**
     * A message retried on the failure transport (messenger:failed:retry, the handler failing again, that
     * transport's retry strategy): it keeps the failure stamp of its first trip, and is a retry like any
     * other, so it goes back to the subject it arrived on, as nats mode leaves it to NATS for the same reason.
     */
    public function testSendPublishesARetryOnTheFailureTransportToTheSubjectItArrivedOn(): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic.retried.42');

        $transport->send(new Envelope(new \stdClass(), [
            new ReceivedStamp('failed'),
            new NatsReceivedSubjectStamp('test-topic.retried.42'),
            new SentToFailureTransportStamp('source'),
            new RedeliveryStamp(0),
            new RedeliveryStamp(1),
        ]));
    }

    /**
     * The consumer delivered the message from that subject, so its filters on the server match it, even when this
     * transport's own options do not list it (the durable widened by hand, say).
     */
    public function testSendPublishesARetryToTheReceivedSubjectEvenWhenTheOptionsDoNotListIt(): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic.edited.42');

        $transport->send(new Envelope(new \stdClass(), [
            new ReceivedStamp('test'),
            new NatsReceivedSubjectStamp('test-topic.edited.42'),
            new RedeliveryStamp(1),
        ]));
    }

    public function testSendPrefersTheStampOverTheReceivedSubject(): void
    {
        $transport = $this->transportExpectingPublishTo('test-topic');

        $transport->send(new Envelope(new \stdClass(), [
            new ReceivedStamp('test'),
            new NatsReceivedSubjectStamp('test-topic.updated.42'),
            new RedeliveryStamp(1),
            new NatsSubjectStamp('test-topic'),
        ]));
    }

    /**
     * The message waits under the topic, where the consumer never reads, and is delivered to the stamped subject.
     */
    public function testSendSchedulesADelayedStampedMessageUnderTheTopicForTheStampedSubject(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('publish')
            ->with(
                self::matchesRegularExpression('/^test-topic\.delayed\.[0-9a-f-]{36}$/'),
                'encoded',
                self::callback(static fn (array $headers): bool => ($headers['Nats-Schedule-Target'] ?? null) === 'test-topic.updated.42'
                    && str_starts_with((string) ($headers['Nats-Schedule'] ?? ''), '@at ')),
            )
            ->willReturn(Future::complete());
        $transport = $this->transport($jetStream, ['scheduled_messages' => true]);

        $transport->send(new Envelope(new \stdClass(), [new NatsSubjectStamp('test-topic.updated.42'), new DelayStamp(5000)]));
    }

    public function testSendSchedulesADelayedMessageForTheTopicWhenTheConsumerDoesNotReadTheStampedSubject(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('publish')
            ->with(
                self::matchesRegularExpression('/^test-topic\.delayed\.[0-9a-f-]{36}$/'),
                'encoded',
                self::callback(static fn (array $headers): bool => ($headers['Nats-Schedule-Target'] ?? null) === 'test-topic'
                    && str_starts_with((string) ($headers['Nats-Schedule'] ?? ''), '@at ')),
            )
            ->willReturn(Future::complete());
        $transport = $this->transport($jetStream, ['scheduled_messages' => true]);

        $transport->send(new Envelope(new \stdClass(), [new NatsSubjectStamp('other-topic.updated.1'), new DelayStamp(5000)]));
    }

    /**
     * JetStream deduplicates per stream whatever the subject, so a Nats-Msg-Id naming the topic dropped the copy
     * one transport sent to a second stamped subject under the same deduplication id.
     */
    public function testSendGivesTheCopiesForTwoStampedSubjectsMessageIdsOfTheirOwn(): void
    {
        $transport = $this->transport($this->jetStreamRecordingMessageIds($messageIds));

        $transport->send(new Envelope(new \stdClass(), [new DeduplicationIdStamp('order-42'), new NatsSubjectStamp('test-topic.updated.1')]));
        $transport->send(new Envelope(new \stdClass(), [new DeduplicationIdStamp('order-42'), new NatsSubjectStamp('test-topic.updated.2')]));

        self::assertSame(['order-42:test-topic.updated.1:0', 'order-42:test-topic.updated.2:0'], $messageIds);
    }

    public function testSendGivesATransportThatDoesNotReadTheStampedSubjectAMessageIdOfItsOwn(): void
    {
        $orders = $this->transport($this->jetStreamRecordingMessageIds($ordersIds));
        $audit = new JetStreamInjectedNatsTransport('nats://localhost:4222/test-stream/audit-topic', [], $this->encodingSerializer());
        $audit->setJetStreamContext($this->jetStreamRecordingMessageIds($auditIds));

        $audit->send($orders->send(new Envelope(new \stdClass(), [new DeduplicationIdStamp('order-42'), new NatsSubjectStamp('test-topic.updated.42')])));

        self::assertSame(['order-42:test-topic.updated.42:0'], $ordersIds);
        self::assertSame(['order-42:audit-topic:0'], $auditIds);
    }

    #[DataProvider('deduplicateProvider')]
    public function testAStampedMessageRoutedToTwoTransportsGoesToItsSubjectFromOneAndToTheTopicFromTheOther(bool $deduplicate): void
    {
        $orders = $this->transportExpectingPublishTo('test-topic.updated.42', ['deduplicate' => $deduplicate]);
        $auditJetStream = $this->createMock(JetStreamContext::class);
        $auditJetStream->expects(self::once())
            ->method('publish')
            ->with('audit-topic', 'encoded', [])
            ->willReturn(Future::complete());
        $audit = new JetStreamInjectedNatsTransport('nats://localhost:4222/audit-stream/audit-topic', ['deduplicate' => $deduplicate], $this->encodingSerializer());
        $audit->setJetStreamContext($auditJetStream);
        $bus = new MessageBus([new SendMessageMiddleware(new SendersLocator(
            [\stdClass::class => ['orders', 'audit']],
            new ServiceLocator(['orders' => static fn () => $orders, 'audit' => static fn () => $audit]),
        ))]);

        $bus->dispatch(new Envelope(new \stdClass(), [new NatsSubjectStamp('test-topic.updated.42')]));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function deduplicateProvider(): iterable
    {
        yield 'deduplicate off' => [false];
        yield 'deduplicate on' => [true];
    }

    /**
     * The id names the schedule's target, not the `{topic}.delayed.{uuid}` subject the message waits on, whose fresh
     * uuid would make every id unique.
     */
    public function testSendNamesTheScheduleTargetInTheMessageIdOfADelayedStampedMessage(): void
    {
        $transport = $this->transport($this->jetStreamRecordingMessageIds($messageIds), ['scheduled_messages' => true]);

        $transport->send(new Envelope(new \stdClass(), [
            new DeduplicationIdStamp('order-42'),
            new NatsSubjectStamp('test-topic.updated.42'),
            new DelayStamp(5000),
        ]));

        self::assertSame(['order-42:test-topic.updated.42:0'], $messageIds);
    }

    public function testSendNamesTheReceivedSubjectInTheMessageIdOfARetry(): void
    {
        $transport = $this->transport($this->jetStreamRecordingMessageIds($messageIds));

        $transport->send(new Envelope(new \stdClass(), [
            new DeduplicationIdStamp('order-42'),
            new ReceivedStamp('test'),
            new NatsReceivedSubjectStamp('test-topic.updated.42'),
            new RedeliveryStamp(1),
        ]));

        self::assertSame(['order-42:test-topic.updated.42:1'], $messageIds);
    }

    public function testSendNamesTheTopicInTheMessageIdOfAFailureTransportCopy(): void
    {
        $transport = $this->transport($this->jetStreamRecordingMessageIds($messageIds));

        $transport->send(new Envelope(new \stdClass(), [
            new DeduplicationIdStamp('order-42'),
            new ReceivedStamp('source'),
            new NatsReceivedSubjectStamp('source-topic.updated.42'),
            new RedeliveryStamp(3),
            new SentToFailureTransportStamp('source'),
            new DelayStamp(0),
            new RedeliveryStamp(0),
        ]));

        self::assertSame(['order-42:test-topic:0:failed:source'], $messageIds);
    }

    public function testGetStampsTheSubjectTheMessageArrivedOn(): void
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic.updated.42', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.1.1.0.0', payload: 'encoded'),
        ]));
        $transport = $this->transport($jetStream);

        $envelopes = [...$transport->get()];

        self::assertCount(1, $envelopes);
        self::assertSame('test-topic.updated.42', $envelopes[0]->last(NatsReceivedSubjectStamp::class)?->subject);
    }

    public function testGetAddsNoReceivedSubjectStampWithoutAdditionalSubjects(): void
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.1.1.0.0', payload: 'encoded'),
        ]));
        $transport = $this->transport($jetStream, ['additional_subjects' => null]);

        $envelopes = [...$transport->get()];

        self::assertCount(1, $envelopes);
        self::assertNull($envelopes[0]->last(NatsReceivedSubjectStamp::class));
    }

    public function testSetupCreatesTheStreamAndConsumerWithTheAdditionalSubjects(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->with(self::callback(static fn (StreamConfiguration $config): bool => ($config->toArray()['subjects'] ?? null) === ['test-topic', self::EXTRA, 'test-topic.delayed.>']))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static function (ConsumerConfiguration $config): bool {
                $options = $config->toArray();

                return ($options['filter_subjects'] ?? null) === ['test-topic', self::EXTRA]
                    && !array_key_exists('filter_subject', $options);
            }))
            ->willReturn(Future::complete($this->consumerInfo(['filter_subjects' => [self::EXTRA, 'test-topic']])));
        $transport = $this->transport($jetStream, ['scheduled_messages' => true]);

        $transport->setup();
    }

    public function testSetupRefusesAdditionalSubjectsOnAServerBeforeNats210(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method(self::anything());
        $transport = $this->transport($jetStream);
        $transport->setClient($this->clientOfAServerReporting('2.9.25'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("The 'additional_subjects' option requires NATS Server >= 2.10, but the connected server reports 2.9.25.");

        $transport->setup();
    }

    public function testSetupGoesOnWithAdditionalSubjectsOnNats210(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->method('addConsumer')->willReturn(Future::complete($this->consumerInfo(['filter_subjects' => ['test-topic', self::EXTRA]])));
        $transport = $this->transport($jetStream);
        $transport->setClient($this->clientOfAServerReporting('2.10.0'));

        $transport->setup();
    }

    /**
     * `messenger:setup-transports` calls setup() on a transport that has not connected yet, whose client knows no
     * server version until it connects: the version is read after the connect.
     */
    public function testTheFirstSetupOfATransportThatHasNotConnectedRefusesAdditionalSubjectsOnAServerBeforeNats210(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method(self::anything());
        $transport = new JetStreamInjectedNatsTransport(self::DSN, ['additional_subjects' => self::EXTRA], $this->encodingSerializer());
        $transport->connectsTo($this->clientOfAServerReporting('2.9.25'), $jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("The 'additional_subjects' option requires NATS Server >= 2.10, but the connected server reports 2.9.25.");

        $transport->setup();
    }

    /**
     * A server that reports no version, or one that names no release, is let through, for the server to decide.
     */
    #[DataProvider('serverVersionsWithoutAReleaseProvider')]
    public function testSetupLetsAServerWhoseReleaseItCannotReadThroughWithAdditionalSubjects(?string $version): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete($this->consumerInfo(['filter_subjects' => ['test-topic', self::EXTRA]])));
        $transport = $this->transport($jetStream);
        $transport->setClient($this->clientOfAServerReporting($version));

        $transport->setup();
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function serverVersionsWithoutAReleaseProvider(): iterable
    {
        yield 'no server info' => [null];
        yield 'an empty version' => [''];
        yield 'a version that names no release' => ['unknown'];
    }

    /**
     * additional_subjects does not take over the message of another feature the server lacks.
     */
    public function testSetupKeepsTheScheduledMessagesRefusalWithAdditionalSubjects(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method(self::anything());
        $transport = $this->transport($jetStream, ['scheduled_messages' => true]);
        $transport->setClient($this->clientOfAServerReporting('2.11.17'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("The 'scheduled_messages' option requires NATS Server >= 2.12, but the connected server reports 2.11.17. Disable scheduled_messages or upgrade NATS.");

        $transport->setup();
    }

    /**
     * A server before NATS 2.10 gets `filter_subject` with the topic, as from 5.10.1, whatever filter its consumer
     * has: it ignores `filter_subjects`, which the union with the live filter would need, and would leave the
     * consumer unfiltered (measured on nats-server 2.9.25 with client 2.30.1), and it takes a changed filter.
     */
    public function testSetupSendsTheTopicAloneToAServerBeforeNats210(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->method('getConsumer')->willReturn(Future::complete($this->consumerInfo(['filter_subject' => 'old-topic'])));
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static function (ConsumerConfiguration $config): bool {
                $options = $config->toArray();

                return ($options['filter_subject'] ?? null) === 'test-topic' && !array_key_exists('filter_subjects', $options);
            }))
            ->willReturn(Future::complete($this->consumerInfo(['filter_subject' => 'test-topic'])));
        $transport = $this->transport($jetStream, ['additional_subjects' => null]);
        $transport->setClient($this->clientOfAServerReporting('2.9.25'));

        $transport->setup();
    }

    /**
     * Without additional_subjects too: a topic renamed in the DSN, the stream and the consumer name kept, is added
     * beside the old one on NATS 2.10 and later, where 5.10.1 replaced it. The old filter stays until someone removes
     * it (README, "Removing a pattern").
     */
    public function testSetupAddsARenamedTopicBesideTheOldOneOnAnExistingConsumer(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->method('getConsumer')->willReturn(Future::complete($this->consumerInfo(['filter_subject' => 'old-topic'])));
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static function (ConsumerConfiguration $config): bool {
                $options = $config->toArray();

                return ($options['filter_subjects'] ?? null) === ['test-topic', 'old-topic'] && !array_key_exists('filter_subject', $options);
            }))
            ->willReturn(Future::complete($this->consumerInfo(['filter_subjects' => ['test-topic', 'old-topic']])));
        $transport = $this->transport($jetStream, ['additional_subjects' => null]);
        $transport->setClient($this->clientOfAServerReporting('2.10.29'));

        $transport->setup();
    }

    public function testSetupAddsTheAdditionalSubjectsToAnExistingStream(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('stream already exists', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::complete(new StreamInfo(
                name: 'test-stream',
                subjects: ['test-topic', 'operator.subject'],
                raw: ['config' => ['name' => 'test-stream', 'subjects' => ['test-topic', 'operator.subject'], 'storage' => 'file']],
            )));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(static fn (array $options): bool => ($options['subjects'] ?? null) === ['test-topic', 'operator.subject', self::EXTRA]))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete($this->consumerInfo(['filter_subjects' => ['test-topic', self::EXTRA]])));
        $transport = $this->transport($jetStream);

        $transport->setup();
    }

    /**
     * @param list<string> $existingFilters
     */
    #[DataProvider('existingConsumerFiltersProvider')]
    public function testSetupKeepsTheFiltersOfAnExistingConsumer(?string $additionalSubjects, array $existingFilters): void
    {
        $written = ['test-topic', 'test-topic.updated.>'];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->method('getConsumer')->willReturn(Future::complete($this->consumerInfo(['filter_subjects' => $existingFilters])));
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static fn (ConsumerConfiguration $config): bool => ($config->toArray()['filter_subjects'] ?? null) === $written))
            ->willReturn(Future::complete($this->consumerInfo(['filter_subjects' => $written])));
        $transport = $this->transport($jetStream, ['additional_subjects' => $additionalSubjects]);

        $transport->setup();
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function existingConsumerFiltersProvider(): iterable
    {
        yield 'a pattern the configuration lacks' => [null, ['test-topic', 'test-topic.updated.>']];
        yield 'a pattern a configured one covers' => ['test-topic.updated.>', ['test-topic', 'test-topic.updated.eu.>']];
        yield 'a pattern that covers a configured one' => ['test-topic.updated.eu.>', ['test-topic', 'test-topic.updated.>']];
    }

    /**
     * JetStream's consumer create is an update when the durable exists (the Behat scenario `Setup widens the
     * filters of an existing consumer when a pattern is added` shows it on a live server), so the reply normally
     * carries the filters setup() wrote. The check after it is a guard: a server before NATS 2.10 does not know `filter_subjects` and
     * creates the consumer unfiltered.
     */
    public function testSetupRejectsAConsumerTheServerCreatedWithoutTheFilterSubjects(): void
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->method('addConsumer')->willReturn(Future::complete($this->consumerInfo([])));
        $transport = $this->transport($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Consumer filter subjects [] do not match the topic and additional_subjects [test-topic, test-topic.updated.>].');

        $transport->setup();
    }

    /**
     * The same guard against a reply whose filters are not the configured ones, whatever the server's reason.
     */
    public function testSetupRejectsAConsumerWithDifferentFilterSubjects(): void
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->method('addConsumer')->willReturn(Future::complete($this->consumerInfo(['filter_subjects' => ['test-topic', 'test-topic.other.>']])));
        $transport = $this->transport($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Consumer filter subjects [test-topic, test-topic.other.>] do not match the topic and additional_subjects [test-topic, test-topic.updated.>].');

        $transport->setup();
    }

    /**
     * The filters are compared as strings: a reply with `10` does not cover a configured `1e1`, though PHP's loose
     * comparison takes the two for the same number. The message lists both sides in their own order, the reply's
     * as the server gave it.
     */
    public function testSetupComparesTheFilterSubjectsAsStrings(): void
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->method('addConsumer')->willReturn(Future::complete($this->consumerInfo(['filter_subjects' => ['test-topic', '10']])));
        $transport = $this->transport($jetStream, ['additional_subjects' => '1e1,10']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Consumer filter subjects [test-topic, 10] do not match the topic and additional_subjects [test-topic, 1e1, 10].');

        $transport->setup();
    }

    /**
     * NATS keeps filter_subject and filter_subjects apart; the check reads whichever the reply carries, and takes
     * an empty filter_subjects for absent.
     *
     * @param array<string, mixed> $filter
     */
    #[DataProvider('reportedFilterShapesProvider')]
    public function testSetupAcceptsEitherFilterFieldTheServerReports(?string $additionalSubjects, array $filter): void
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->method('addConsumer')->willReturn(Future::complete($this->consumerInfo($filter)));
        $transport = $this->transport($jetStream, ['additional_subjects' => $additionalSubjects]);

        $transport->setup();

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{?string, array<string, mixed>}>
     */
    public static function reportedFilterShapesProvider(): iterable
    {
        yield 'topic in filter_subject' => [null, ['filter_subject' => 'test-topic']];
        yield 'topic in a one-element filter_subjects' => [null, ['filter_subjects' => ['test-topic']]];
        yield 'empty filter_subjects next to filter_subject' => [null, ['filter_subjects' => [], 'filter_subject' => 'test-topic']];
        yield 'topic and extra in filter_subjects, in another order' => [self::EXTRA, ['filter_subjects' => [self::EXTRA, 'test-topic']]];
        yield 'empty filter_subject next to filter_subjects' => [self::EXTRA, ['filter_subject' => '', 'filter_subjects' => ['test-topic', self::EXTRA]]];
        yield 'a filter reported as a number' => ['10', ['filter_subjects' => ['test-topic', 10]]];
    }

    public function testSetupRejectsAConsumerReportingNoFilter(): void
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->method('addConsumer')->willReturn(Future::complete($this->consumerInfo(['filter_subjects' => []])));
        $transport = $this->transport($jetStream, ['additional_subjects' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Consumer filter subject does not match the configured topic.');

        $transport->setup();
    }

    /**
     * Without additional_subjects the consumer keeps the filter_subject field it always had.
     */
    public function testSetupKeepsASingleFilterSubjectWithoutAdditionalSubjects(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static function (ConsumerConfiguration $config): bool {
                $options = $config->toArray();

                return ($options['filter_subject'] ?? null) === 'test-topic' && !array_key_exists('filter_subjects', $options);
            }))
            ->willReturn(Future::complete($this->consumerInfo(['filter_subject' => 'test-topic'])));
        $transport = $this->transport($jetStream, ['additional_subjects' => null]);

        $transport->setup();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function transportExpectingPublishTo(string $subject, ?array $options = null): JetStreamInjectedNatsTransport
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('publish')
            ->with($subject, 'encoded', [])
            ->willReturn(Future::complete());

        return $this->transport($jetStream, $options ?? []);
    }

    /**
     * @param list<?string>|null $messageIds
     */
    private function jetStreamRecordingMessageIds(?array &$messageIds): JetStreamContext
    {
        $messageIds = [];
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('publish')->willReturnCallback(
            static function (string $subject, string $payload, array $headers = [], ?string $msgId = null) use (&$messageIds): Future {
                $messageIds[] = $msgId;

                return Future::complete();
            },
        );

        return $jetStream;
    }

    private function clientOfAServerReporting(?string $version): NatsClient
    {
        $client = $this->createStub(NatsClient::class);
        $client->method('state')->willReturn(ConnectionState::Open);
        $client->method('serverInfo')->willReturn($version === null ? null : new ServerInfo('id', 'name', $version, true, 1048576, true));

        return $client;
    }

    private function encodingSerializer(): SerializerInterface
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        return $serializer;
    }

    /**
     * A transport with additional_subjects set to {@see self::EXTRA} unless the options override it.
     *
     * @param array<string, mixed> $options
     */
    private function transport(JetStreamContext $jetStream, array $options = []): JetStreamInjectedNatsTransport
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $serializer->method('decode')->willReturn(new Envelope(new \stdClass()));

        $transport = new JetStreamInjectedNatsTransport(self::DSN, $options + ['additional_subjects' => self::EXTRA], $serializer);
        $transport->setJetStreamContext($jetStream);

        return $transport;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function consumerInfo(array $filter): ConsumerInfo
    {
        return new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all'] + $filter],
        );
    }
}
