-- Local ranking-only duplicates discovered during production preflight.
-- These IDs belong to the backed-up local dataset; production has no such profiles.
-- Guards reject profiles with tournament results or games, and ranking collisions.
BEGIN;
SELECT pg_advisory_xact_lock(73673,21);
CREATE TEMP TABLE waiting_identity_merge(source_id int,target_id int) ON COMMIT DROP;
INSERT INTO waiting_identity_merge VALUES(5122,3442),(5129,4304);
DO $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM player WHERE id=3442 AND name_show='Iwona Skubis-Galus') OR NOT EXISTS(SELECT 1 FROM player WHERE id=4304 AND name_show='Jacek Jędrulok')
 OR EXISTS(SELECT 1 FROM player WHERE id=5122 AND name_show<>'Iwona Skubis') OR EXISTS(SELECT 1 FROM player WHERE id=5129 AND name_show<>'Jacek Jendrulok') THEN RAISE EXCEPTION 'Unexpected local profiles'; END IF;
 IF EXISTS(SELECT 1 FROM tournament_result r JOIN waiting_identity_merge m ON r.player_id=m.source_id) OR EXISTS(SELECT 1 FROM tournament_game g JOIN waiting_identity_merge m ON g.player1_id=m.source_id OR g.player2_id=m.source_id) THEN RAISE EXCEPTION 'Expected ranking-only profiles'; END IF;
 IF EXISTS(SELECT 1 FROM ranking a JOIN waiting_identity_merge m ON a.player_id=m.source_id JOIN ranking b ON b.player_id=m.target_id AND a.organization_id=b.organization_id AND a.tournament_id=b.tournament_id AND a.rtype=b.rtype) THEN RAISE EXCEPTION 'Conflicting ranking rows'; END IF;
END $$;
UPDATE ranking r SET player_id=m.target_id FROM waiting_identity_merge m WHERE r.player_id=m.source_id;
INSERT INTO player_organization(player_id,organization_id,is_admin) SELECT m.target_id,p.organization_id,p.is_admin FROM player_organization p JOIN waiting_identity_merge m ON p.player_id=m.source_id ON CONFLICT(player_id,organization_id) DO UPDATE SET is_admin=player_organization.is_admin OR excluded.is_admin;
DELETE FROM player_organization p USING waiting_identity_merge m WHERE p.player_id=m.source_id;
DELETE FROM player p USING waiting_identity_merge m WHERE p.id=m.source_id;
COMMIT;
