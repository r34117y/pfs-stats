# Tournament Round Logging

The `POST /tournament` endpoint writes two dedicated logs.

## Payload Log

Path: `var/log/tournament_round_payload.log`

Behavior:
- The processor saves the raw HTTP request body before authorization and import logic runs.
- The JSON payload is written as received. It is not decoded, normalized, or re-encoded.
- A trailing newline is appended only when the request body does not already end with one, so consecutive payloads remain readable in a single file.

## Error Log

Path: `var/log/tournament_round_error.log`

Behavior:
- Import failures for the endpoint are written to the dedicated Monolog channel `tournament_round_error`.
- Logged context includes the exception class, exception message, stack trace, and the raw request payload.
- Failures to create or append the payload log are also written here.

## Operational Notes

- Both logs are local filesystem logs under `var/log/`.
- The payload log can contain authorization tokens and personal data from tournament submissions, so it should be handled as sensitive operational data.
- Rotation and retention should be configured at the deployment level if these files are expected to grow quickly.
