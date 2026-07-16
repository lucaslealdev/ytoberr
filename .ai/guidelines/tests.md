# Test Fixtures

- Never use real YouTube video/channel IDs in tests (mock yt-dlp output, factories, assertions, etc.), even ones taken from a bug report or production warning. Always use an obviously-fake placeholder ID instead (e.g. `members_only_vid`, `not_due_vid`, or a made-up 11-character string).
