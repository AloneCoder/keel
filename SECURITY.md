# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 1.x | yes |
| < 1.0 | no |

## Reporting a vulnerability

Do not open a public issue for a security problem. Use GitHub's private vulnerability
reporting on this repository (Security → Report a vulnerability) or write to
popov.max.31337@gmail.com with "keel security" in the subject.

Please include the affected component (container, router, http, auth, session, ...), a
minimal reproduction and the impact you see. You will get an acknowledgement within 72 hours
and a fix or a mitigation plan within 14 days for confirmed issues. Credit goes in the
changelog unless you prefer otherwise.

## Scope

What counts: anything in `src/` that lets a request do what the application did not allow,
read what it should not, or take a worker down (bypassed `#[Auth]`, placeholder constraint
escapes, session forgery, header injection, state leaking between requests in worker mode,
denial of service through a single request).

What does not count: vulnerabilities in applications built on Keel that stem from their own
code or configuration, missing hardening headers (set them in your middleware), and reports
from automated scanners without a working reproduction.

## Regression suite

`tests/Security` keeps a regression test for every class of problem found so far:

```bash
vendor/bin/phpunit --group security
```
