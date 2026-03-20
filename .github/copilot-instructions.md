# Repository Instructions

- Always read `AGENTS.md` at the start of every task for documentation maintenance rules.
- After every code modification, run the relevant verification commands before finishing the task.
- For PHP source changes, run `composer test` at minimum.
- When coverage-sensitive code or CI/test tooling changes, run `composer test:unit`.
- When transport wiring, Docker/NATS setup, or functional scenarios change, run `composer test:functional:setup`, `composer nats:start`, `composer test:functional`, and `composer nats:stop`.
- Prefer the composer scripts in the root `composer.json` over ad-hoc commands or Makefile targets.
- Keep `docs/TESTS.md` up to date whenever tests are added, removed, or renamed.
- Add an entry to `docs/CHANGELOG.md` for each release, following Keep a Changelog format.
- When a PR is merged or its features are adapted, add a description to `docs/PRs/`.