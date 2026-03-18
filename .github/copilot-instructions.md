# Repository Instructions

- After every code modification, run the relevant verification commands before finishing the task.
- For PHP source changes, run `composer test` at minimum.
- When coverage-sensitive code or CI/test tooling changes, run `composer test:unit`.
- When transport wiring, Docker/NATS setup, or functional scenarios change, run `composer test:functional:setup`, `composer nats:start`, `composer test:functional`, and `composer nats:stop`.
- Prefer the composer scripts in the root `composer.json` over ad-hoc commands or Makefile targets.