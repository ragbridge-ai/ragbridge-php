# 1. Record architecture decisions

- Status: Accepted
- Date: 2026-09-21

## Context

Design choices in this package, such as which HTTP abstractions to depend on or how
framework support is layered, shape what users can rely on. The reasoning behind them is
easy to lose once the code is merged, which makes later changes harder to evaluate.

## Decision

Significant architectural decisions are recorded as Architecture Decision Records in
`docs/adr/`. Each record is a short Markdown file named `NNNN-title.md` with the sections
Title, Status, Date, Context, Decision and Consequences.

An accepted record is not edited. When a decision changes, a new record supersedes the old
one and the old record's status is changed to "Superseded by NNNN".

## Consequences

- The rationale for a decision stays next to the code and is reviewed in the same pull
  request.
- Reversing a decision is an explicit, documented step instead of a silent change.
- Contributors writing a new record need to spend a little time on the context and
  consequences, not only the outcome.
