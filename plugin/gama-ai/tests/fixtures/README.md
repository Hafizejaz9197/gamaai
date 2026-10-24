# Test fixtures

Every `*.txt` file here is checked for a byte-exact round trip.

- `synthetic-*.txt`: hand-written in the shortcode format Avada Builder 3.15
  registers (see docs/phase0-avada-findings.md).
- `real-*.txt`: markup saved by Avada Builder 3.15.6 on the test site,
  transcribed from the page's raw content. `real-test-page-two-columns.txt` is
  page 11 as saved on 2026-10-24 (a container with two 1/2 columns, a title
  and an image). The transcription may differ from the stored page by a few
  bytes; attribute names, order and values are as Avada wrote them.
