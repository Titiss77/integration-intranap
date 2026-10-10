# Base PEC

The database keeps the federal tables (`nageurs`, `epreuves`, `lieux`, `saisons`, `performances`) and adds clubs and a swimmer/club/season roster. Public performance queries only show swimmers registered with PEC for the selected season.

## Initialize a database

1. Create an empty MySQL database using `utf8mb4`.
2. Import `b7_41910034_intranap_club.sql` into it.
3. Run `001_club_scope.sql` once.
4. Configure `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and `API_CLUB=PEC` in `htdocs/.env`.

The migration adds the tables used by the site and synchronization, adds `classement` for PDF imports, and expands the performance uniqueness key to include season and venue. Existing dump rows seed the PEC roster by season. `bassin_m` stays nullable because the current FFESSM API response does not provide reliable pool length data.

Apply the migration to both local and hosted databases before deploying code that uses `clubs` and `club_memberships`. Do not run it again on an already migrated database.
