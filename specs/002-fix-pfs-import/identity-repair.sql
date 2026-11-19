-- Confirmed by the user on 2026-11-19. Local IDs are verified below.
-- Execute only against the backed-up local dataset or its acceptance clones.
BEGIN;
SELECT pg_advisory_xact_lock(73673, 21);
CREATE TEMP TABLE pfs_identity_merge (source_id int PRIMARY KEY, target_id int) ON COMMIT DROP;
INSERT INTO pfs_identity_merge VALUES (3468, 767), (5127, 337);
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM organization WHERE id=21 AND lower(code)='pfs') THEN
        RAISE EXCEPTION 'Wrong organization/dataset';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM player WHERE id=767 AND name_show='Michał Waszkiewicz')
       OR NOT EXISTS (SELECT 1 FROM player WHERE id=337 AND name_show IN ('Zuzanna Adamczewska','Zuzanna Stasiak'))
       OR EXISTS (SELECT 1 FROM player WHERE id=3468 AND name_show<>'Michał.Piotr Waszkiewicz')
       OR EXISTS (SELECT 1 FROM player WHERE id=5127 AND name_show<>'Zuzanna Stasiak') THEN
        RAISE EXCEPTION 'Unexpected profile IDs; do not copy IDs to another dataset';
    END IF;
    IF EXISTS (SELECT 1 FROM tournament_result a JOIN pfs_identity_merge m ON a.player_id=m.source_id
               JOIN tournament_result b ON b.player_id=m.target_id AND b.tournament_id=a.tournament_id)
       OR EXISTS (SELECT 1 FROM ranking a JOIN pfs_identity_merge m ON a.player_id=m.source_id
                  JOIN ranking b ON b.player_id=m.target_id AND b.tournament_id=a.tournament_id
                    AND b.organization_id=a.organization_id AND b.rtype=a.rtype)
       OR EXISTS (SELECT 1 FROM tournament_game g JOIN pfs_identity_merge m ON g.player1_id=m.source_id
                  WHERE g.player2_id=m.target_id)
       OR EXISTS (SELECT 1 FROM app_user a JOIN pfs_identity_merge m ON a.player_id=m.source_id
                  JOIN app_user b ON b.player_id=m.target_id) THEN
        RAISE EXCEPTION 'Overlapping domain data requires manual reconciliation';
    END IF;
END $$;

-- Existing legacy summaries store counts and means. Merge means by their
-- original denominator, retaining all annual and organization-specific rows.
UPDATE play_summary t SET
    grank=(coalesce(t.grank,0)*t.games+coalesce(s.grank,0)*s.games)/nullif(t.games+s.games,0),
    points=(coalesce(t.points,0)*t.games+coalesce(s.points,0)*s.games)/nullif(t.games+s.games,0),
    pointo=(coalesce(t.pointo,0)*t.games+coalesce(s.pointo,0)*s.games)/nullif(t.games+s.games,0),
    pointsw=(coalesce(t.pointsw,0)*t.gwin+coalesce(s.pointsw,0)*s.gwin)/nullif(t.gwin+s.gwin,0),
    pointow=(coalesce(t.pointow,0)*t.gwin+coalesce(s.pointow,0)*s.gwin)/nullif(t.gwin+s.gwin,0),
    pointsl=(coalesce(t.pointsl,0)*t.glost+coalesce(s.pointsl,0)*s.glost)/nullif(t.glost+s.glost,0),
    pointol=(coalesce(t.pointol,0)*t.glost+coalesce(s.pointol,0)*s.glost)/nullif(t.glost+s.glost,0),
    gwin=t.gwin+s.gwin,glost=t.glost+s.glost,gdraw=t.gdraw+s.gdraw,games=t.games+s.games,
    gwinnw=t.gwinnw+s.gwinnw,glostnw=t.glostnw+s.glostnw,gdrawnw=t.gdrawnw+s.gdrawnw,gamesnw=t.gamesnw+s.gamesnw,
    gwin130=t.gwin130+s.gwin130,games130=t.games130+s.games130,
    gwin110=t.gwin110+s.gwin110,games110=t.games110+s.games110,
    gwin100=t.gwin100+s.gwin100,games100=t.games100+s.games100,
    over350=t.over350+s.over350,over400=t.over400+s.over400,over500=t.over500+s.over500,over600=t.over600+s.over600
FROM play_summary s, pfs_identity_merge m
WHERE s.player_id=m.source_id AND t.player_id=m.target_id
  AND t.organization_id=s.organization_id AND t.stype=s.stype;
DELETE FROM play_summary s USING pfs_identity_merge m,play_summary t
WHERE s.player_id=m.source_id AND t.player_id=m.target_id
  AND t.organization_id=s.organization_id AND t.stype=s.stype;
UPDATE play_summary p SET player_id=m.target_id FROM pfs_identity_merge m WHERE p.player_id=m.source_id;
INSERT INTO player_organization (player_id,organization_id,is_admin)
SELECT m.target_id,p.organization_id,p.is_admin FROM player_organization p
JOIN pfs_identity_merge m ON p.player_id=m.source_id
ON CONFLICT (player_id,organization_id) DO UPDATE SET is_admin=player_organization.is_admin OR excluded.is_admin;
DELETE FROM player_organization p USING pfs_identity_merge m WHERE p.player_id=m.source_id;
UPDATE tournament_game g SET player1_id=m.target_id FROM pfs_identity_merge m WHERE g.player1_id=m.source_id;
UPDATE tournament_game g SET player2_id=m.target_id FROM pfs_identity_merge m WHERE g.player2_id=m.source_id;
UPDATE tournament_result r SET player_id=m.target_id FROM pfs_identity_merge m WHERE r.player_id=m.source_id;
UPDATE ranking r SET player_id=m.target_id FROM pfs_identity_merge m WHERE r.player_id=m.source_id;
UPDATE tournament t SET winner_player_id=m.target_id FROM pfs_identity_merge m WHERE t.winner_player_id=m.source_id;
UPDATE app_user u SET player_id=m.target_id FROM pfs_identity_merge m WHERE u.player_id=m.source_id;
UPDATE game_photo p SET uploaded_by_player_id=m.target_id FROM pfs_identity_merge m WHERE p.uploaded_by_player_id=m.source_id;
DELETE FROM player p USING pfs_identity_merge m WHERE p.id=m.source_id;
UPDATE player SET name_show='Zuzanna Stasiak',name_alph='Stasiak Zuzanna',last_name='Stasiak',slug='zuzanna-stasiak',city='Włocławek' WHERE id=337;
COMMIT;
