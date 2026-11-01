# Security policy

Worker Supervisor runs root-side code on behalf of untrusted cPanel accounts, so a flaw can let one account act as root or as another account. Please report it privately.

## Reporting

- Preferred: GitHub > this repository > Security > "Report a vulnerability" (private advisory).
- Or email gilbertpaquin1995@gmail.com with "Worker Supervisor security" in the subject.

Include the version (`/usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv --version`), the cPanel and CloudLinux versions, and the steps or proof of concept. Do not open a public issue or pull request for it.

You will get an answer within 7 days. Once a fix is released, the advisory is published with credit to the reporter unless you prefer otherwise.

## Supported versions

Only the latest release receives security fixes.

## Scope

In scope: anything that lets a cPanel account (from the cPanel pages, UAPI, its workers or files it controls) run code or read or write files as root or as another account, act on another account's workers, bypass the limits root set, or reach the WHM pages; and XSS or CSRF against root in WHM or against clients in cPanel.

Out of scope: what an account can already do with its own privileges (its workers run its own code, see "Security model" in the README), and denial of service limited to the account's own workers.
