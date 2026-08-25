<!--
Sync Impact Report
Version change: template -> 1.0.0
Modified principles: N/A - initial ratification from placeholder template
Added sections: Operational Constraints; Development Workflow
Removed sections: N/A
Follow-up TODOs: N/A
-->
# Scrabble Stats API Constitution

## Core Principles

### I. Competition History Is the Product
The application MUST preserve and present competitive Scrabble history for PFS,
Polish Scrabble clubs, players, organizers, and community members. Features MUST
serve rankings, player profiles, tournament results, historical performance,
records, or adjacent operational needs. Domain terminology MUST keep PFS as the
main organization while allowing other organizations for club and future data.

Rationale: The project exists to make tournament data easier to explore,
compare, verify, and understand.

### II. Official Sources and Verifiable Imports
PFS tournament data MUST be imported from the official PFS website when PFS is
the source of record. The legacy statistics website MAY be used as an additional
verification source, but it MUST NOT silently override official PFS data. Import
logic MUST preserve tournament structures that differ from the common 12-round
format, including group stages, knockout stages, one-day events, 13-round events,
and Grand Prix qualification effects.

Club tournament data MUST support manual upload by authorized club admins.
Changes to parsers, matchers, ranking reconstruction, or imported payloads MUST
include regression coverage for representative source formats.

Rationale: The ranking and statistics views are only useful when users can trust
the provenance and shape of the underlying competition data.

### III. Cache Correctness Before Cache Speed
Public read APIs MUST use endpoint-level application cache backed by Redis and
MUST namespace cache keys by dataset version. Data-changing workflows that affect
public reads MUST provide a dataset version bump or an equivalent explicit
invalidation path. Public read APIs MUST emit HTTP cache headers appropriate for
shared caching. Authenticated user profile responses MUST remain private and
no-store.

Doctrine caching in production MAY be used only as a secondary optimization.
Implementations MUST NOT rely on global key scanning as the normal invalidation
strategy.

Rationale: This project has rare updates and computation-heavy statistics, so
performance depends on caching without serving stale or private data incorrectly.

### IV. Symfony and API Platform Boundaries
The application MUST keep Symfony controllers focused on rendering page
skeletons. Page data MUST be loaded from API endpoints, normally through Alpine
JS in Twig templates. API Platform resources MUST represent endpoint contracts,
with providers and processors used according to API Platform conventions.
Reusable services MUST contain business logic needed by API providers, API
processors, console commands, and import workflows.

New PHP code MUST target modern PHP 8.3 or newer style: strict type information
where supported, final classes where extension is not intended, readonly classes
or properties where mutation is not required, and clear ownership between
Resource, State, Service, Command, Entity, Repository, Controller, and Twig code.

Rationale: Clear framework boundaries keep the API, console workflows, and page
shells testable while allowing reuse of ranking and statistics logic.

### V. Tested Behavior Is Required
Every behavior change MUST add or update tests unless the change is purely
documentation or mechanical metadata. API contract changes MUST have endpoint
coverage. Ranking, parser, import, security, cache-header, and user-profile
changes MUST have focused tests for their observable behavior. Existing relevant
tests MUST pass before a change is considered complete.

Responsive page changes MUST preserve usable desktop and mobile layouts. Shared
abstractions MAY be introduced to reduce duplication, but they MUST NOT broaden
the blast radius without tests proving unchanged behavior.

Rationale: The stated project goal is broad test coverage, and ranking or import
regressions can corrupt user-visible historical records.

## Operational Constraints

The production deploy path MUST use `bin/deploy-prod.sh` from the production
checkout. A plain production `docker compose up -d` MUST NOT be treated as a
full application deploy because it can leave stale Symfony cache, public assets,
nginx configuration, or mounted dependencies in place.

Deployments MUST rebuild images when PHP image contents, public assets, or nginx
configuration changed. Production deploys MUST clear and warm Symfony production
cache. Doctrine migrations MUST run through the deploy script unless a deploy is
explicitly marked `--no-migrate` for a justified operational reason.

When `composer.lock` changes, production dependencies in the mounted application
directory MUST be installed with optimized autoloading before production cache is
warmed. Production configuration MUST keep secrets in `.env.local`; required
values include `APP_SECRET` and `POSTGRES_PASSWORD`.

Local Xdebug MAY be enabled in the development PHP image only. Production images
MUST NOT install or enable Xdebug.

## Development Workflow

Implementation plans MUST identify affected endpoint contracts, data sources,
cache keys or dataset version behavior, tests, and deployment implications before
work starts. Work on public read endpoints MUST review cache headers and Redis
cache keys. Work on authenticated endpoints MUST review privacy headers and
authorization behavior.

Developers MUST prefer established project types: Symfony controllers for page
skeletons, Twig templates for HTML shells and Alpine JS wiring, API resources for
endpoints, providers and processors for API Platform state, console commands for
operational tasks, and services for reusable business logic.

Verification MUST use the narrowest meaningful test set first and broaden when a
change touches shared behavior, ranking calculations, parser contracts, cache
behavior, security, or deployment code. Docker-based commands MAY be used to
verify SQL, console behavior, API behavior, and production-like workflows when
local host execution is insufficient.

## Governance

This constitution supersedes conflicting informal development practices. Feature
specifications, plans, tasks, reviews, and implementation work MUST comply with
the Core Principles, Operational Constraints, and Development Workflow sections.

Amendments MUST be made by editing this constitution, adding a Sync Impact Report
for the change, and recording the semantic version impact. MAJOR version changes
apply to removed principles or backward-incompatible governance redefinitions.
MINOR version changes apply to new principles, new sections, or materially
expanded guidance. PATCH version changes apply to clarifications, wording fixes,
or non-semantic refinements.

Compliance review MUST happen during planning and before completion of each
feature. Any exception MUST be documented with its rationale, affected principle,
expiry or revisit condition, and mitigation plan. Exceptions MUST NOT weaken
privacy controls for authenticated user data or provenance requirements for
official competition records.

**Version**: 1.0.0 | **Ratified**: 2026-08-25 | **Last Amended**: 2026-08-25
