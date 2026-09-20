<?php

function scoringTestSuite() {
    return [
        'ambiguous_dob_key_collapses_swapped_dates' => function () {
            assertSameValue(
                '2009-01-12-amb',
                buildAthleteDobIdentityKey('12/01/2009', null)
            );
            assertSameValue(
                buildAthleteDobIdentityKey('12/01/2009', null),
                buildAthleteDobIdentityKey('01/12/2009', null)
            );
        },
        'group_results_merges_same_name_across_clubs_when_dob_matches' => function () {
            $rows = [
                buildTestRow('Melody', 'Lisle', '11/16/2014', 'Ginninderra Athletics', '100m', 'SS #1'),
                buildTestRow('Melody', 'Lisle', '11/16/2014', 'Athletics New South Wales', '200m', 'SS #2'),
            ];

            $athletes = groupResultsByAthlete($rows);

            assertCountValue(1, $athletes);
            $athlete = array_values($athletes)[0];
            assertSameValue(['Athletics New South Wales', 'Ginninderra Athletics'], $athlete['clubs']);
        },
        'group_results_splits_same_name_when_dob_is_genuinely_different' => function () {
            $rows = [
                buildTestRow('Joshua', 'Smith', '11/28/2008', 'New South Wales', '100m', 'SS #1'),
                buildTestRow('Joshua', 'Smith', '12/01/2009', 'Woden Athletics', '200m', 'SS #2'),
            ];

            $athletes = groupResultsByAthlete($rows);

            assertCountValue(2, $athletes);
        },
        'club_scoring_uses_club_scoped_results_without_splitting_athlete_identity' => function () {
            $rows = [
                buildTestRow('Vicki', 'Townsend', '05/12/1964', 'ACT Masters Athletics', '100m', 'SS #1', 61, 'Female', 15.0),
                buildTestRow('Vicki', 'Townsend', '05/12/1964', 'Woden Athletics', '200m', 'SS #2', 61, 'Female', 31.0),
            ];

            $athletes = groupResultsByAthlete($rows);
            $meetEventArray = [
                ['name' => 'SS #1', 'events' => ['100m']],
                ['name' => 'SS #2', 'events' => ['200m']],
            ];
            $clubsData = [
                'ACT Masters Athletics' => ['size' => 10, 'officials' => [0, 0]],
                'Woden Athletics' => ['size' => 10, 'officials' => [0, 0]],
            ];

            $summary = buildAthleteSummaries($athletes, $clubsData, $meetEventArray, false);

            assertCountValue(1, $summary['athletes']);
            assertCountValue(2, $summary['clubs']);

            $clubs = buildClubSummaries($summary['clubs'], $clubsData, $meetEventArray);
            assertCountValue(2, $clubs);

            foreach ($clubs as $clubName => $club) {
                assertCountValue(1, $club['athletes']);
                assertTrueValue($club['score'] > 0, "Expected positive club score for {$clubName}");
            }
        },
        'athlete_score_breakdown_counts_the_best_three_events_in_every_view' => function () {
            $athlete = [
                'age' => 16,
                'events' => [
                    '100m' => [buildTestRow('Taylor', 'Runner', '11/28/2008', 'Woden Athletics', '100m', 'SS #1', 16, 'Male', 12.0)],
                    '200m' => [buildTestRow('Taylor', 'Runner', '11/28/2008', 'Woden Athletics', '200m', 'SS #1', 16, 'Male', 25.0)],
                    '400m' => [buildTestRow('Taylor', 'Runner', '11/28/2008', 'Woden Athletics', '400m', 'SS #1', 16, 'Male', 55.0)],
                    '800m' => [buildTestRow('Taylor', 'Runner', '11/28/2008', 'Woden Athletics', '800m', 'SS #1', 16, 'Male', 135.0)],
                ],
            ];
            $meetEventArray = [['name' => 'SS #1', 'events' => ['100m', '200m', '400m', '800m']]];

            $caBreakdown = buildAthleteScoreBreakdown($athlete, $meetEventArray, false);
            $clubBreakdown = buildAthleteScoreBreakdown($athlete, $meetEventArray, true);

            assertCountValue(3, $caBreakdown['totals']);
            assertCountValue(3, $clubBreakdown['totals']);
            assertSameValue($caBreakdown['total'], $clubBreakdown['total']);
        },
        'event_participation_factor_uses_the_square_root_of_entries_over_opportunities' => function () {
            $athlete = ['age' => 16];
            $results = [buildTestRow('Taylor', 'Runner', '11/28/2008', 'Woden Athletics', '100m', 'SS #1', 16, 'Male', 12.0)];
            $meetEvents = [
                ['name' => 'SS #1', 'events' => ['100m']],
                ['name' => 'SS #2', 'events' => ['100m']],
                ['name' => 'SS #3', 'events' => ['100m']],
                ['name' => 'SS #4', 'events' => ['100m']],
            ];

            $summary = buildAthleteEventSummary($athlete, '100m', $results, $meetEvents);

            assertSameValue(0.5, $summary['participation_score']);
        },
        'club_cpf_helpers_return_numeric_precision' => function () {
            $cpfOld = calcClubParticipationFactor(1, 3);
            $cpfNew = calcAverageAthleteMeetParticipationFactor([
                ['meet_pf' => 1 / 3],
                ['meet_pf' => 2 / 3],
            ]);

            assertTrueValue(is_float($cpfOld), 'Expected old CPF to be float');
            assertTrueValue(is_float($cpfNew), 'Expected new CPF to be float');
            assertSameValue(1 / 3, $cpfOld);
            assertSameValue(0.5, $cpfNew);
        },
        'meet_pf_keeps_attended_u20_open_champs_for_junior_athletes' => function () {
            $athlete = [
                'age' => 16,
                'events' => [
                    '800m' => [
                        buildTestRow('Junior', 'Runner', '11/28/2008', 'Woden Athletics', '800m', 'SS #1', 16, 'Male', 130.0),
                        buildTestRow('Junior', 'Runner', '11/28/2008', 'Woden Athletics', '800m', 'U20 & Opens Champs', 16, 'Male', 131.0),
                    ],
                ],
            ];
            $meetEventArray = [
                ['name' => 'SS #1', 'events' => ['800m']],
                ['name' => 'U20 & Opens Champs', 'events' => ['800m']],
                ['name' => 'U9-U18 Champs', 'events' => ['800m']],
            ];

            $meetPf = buildAthleteMeetParticipationData($athlete, $meetEventArray);

            assertSameValue(2, $meetPf['attended_meet_count']);
            assertSameValue(3, $meetPf['eligible_meet_count']);
            assertSameValue(2 / 3, $meetPf['meet_pf']);
        },
        'meet_pf_keeps_attended_u9_u18_champs_for_senior_athletes' => function () {
            $athlete = [
                'age' => 20,
                'events' => [
                    '800m' => [
                        buildTestRow('Senior', 'Runner', '08/05/2005', 'Woden Athletics', '800m', 'SS #1', 20, 'Male', 130.0),
                        buildTestRow('Senior', 'Runner', '08/05/2005', 'Woden Athletics', '800m', 'U9-U18 Champs', 20, 'Male', 131.0),
                    ],
                ],
            ];
            $meetEventArray = [
                ['name' => 'SS #1', 'events' => ['800m']],
                ['name' => 'U20 & Opens Champs', 'events' => ['800m']],
                ['name' => 'U9-U18 Champs', 'events' => ['800m']],
            ];

            $meetPf = buildAthleteMeetParticipationData($athlete, $meetEventArray);

            assertSameValue(2, $meetPf['attended_meet_count']);
            assertSameValue(3, $meetPf['eligible_meet_count']);
            assertSameValue(2 / 3, $meetPf['meet_pf']);
        },
        'meet_pf_excludes_unattended_u20_open_champs_for_juniors' => function () {
            $athlete = [
                'age' => 16,
                'events' => [
                    '800m' => [
                        buildTestRow('Junior', 'Runner', '11/28/2008', 'Woden Athletics', '800m', 'SS #1', 16, 'Male', 130.0),
                    ],
                ],
            ];
            $meetEventArray = [
                ['name' => 'SS #1', 'events' => ['800m']],
                ['name' => 'U20 & Opens Champs', 'events' => ['800m']],
                ['name' => 'U9-U18 Champs', 'events' => ['800m']],
            ];

            $meetPf = buildAthleteMeetParticipationData($athlete, $meetEventArray);

            assertSameValue(1, $meetPf['attended_meet_count']);
            assertSameValue(2, $meetPf['eligible_meet_count']);
            assertSameValue(0.5, $meetPf['meet_pf']);
        },
        'meet_pf_excludes_unattended_u9_u18_champs_for_seniors' => function () {
            $athlete = [
                'age' => 20,
                'events' => [
                    '800m' => [
                        buildTestRow('Senior', 'Runner', '08/05/2005', 'Woden Athletics', '800m', 'SS #1', 20, 'Male', 130.0),
                    ],
                ],
            ];
            $meetEventArray = [
                ['name' => 'SS #1', 'events' => ['800m']],
                ['name' => 'U20 & Opens Champs', 'events' => ['800m']],
                ['name' => 'U9-U18 Champs', 'events' => ['800m']],
            ];

            $meetPf = buildAthleteMeetParticipationData($athlete, $meetEventArray);

            assertSameValue(1, $meetPf['attended_meet_count']);
            assertSameValue(2, $meetPf['eligible_meet_count']);
            assertSameValue(0.5, $meetPf['meet_pf']);
        },
        'unknown_dob_warning_lists_affected_athletes' => function () {
            $rows = [
                buildTestRow('Alex', 'NoDob', '', 'Woden Athletics', '100m', 'SS #1'),
                buildTestRow('Jordan', 'KnownDob', '11/28/2008', 'Woden Athletics', '200m', 'SS #2'),
                buildTestRow('Alex', 'NoDob', '', 'ACT Masters Athletics', '200m', 'SS #3'),
            ];

            $athletes = groupResultsByAthlete($rows);
            $warnings = collectUnknownDobAthleteNames($athletes);
            $warningHtml = renderDataWarnings($warnings);

            assertSameValue(['Alex NoDob'], $warnings);
            assertContainsValue('Alex NoDob', $warningHtml);
            assertContainsValue('unknown or invalid DOB', $warningHtml);
        },
        'invalid_dob_warning_lists_affected_athletes' => function () {
            $rows = [
                buildTestRow('Casey', 'BadDob', 'foo', 'Woden Athletics', '100m', 'SS #1'),
                buildTestRow('Jordan', 'KnownDob', '11/28/2008', 'Woden Athletics', '200m', 'SS #2'),
            ];

            $athletes = groupResultsByAthlete($rows);
            $warnings = collectUnknownDobAthleteNames($athletes);
            $warningHtml = renderDataWarnings($warnings);

            assertSameValue(['Casey BadDob'], $warnings);
            assertContainsValue('Casey BadDob', $warningHtml);
            assertContainsValue('unknown or invalid DOB', $warningHtml);
        },
        'render_data_warnings_handles_empty_and_multiple_names' => function () {
            assertSameValue('', renderDataWarnings([]));

            $warningHtml = renderDataWarnings(['Alex NoDob', 'Casey BadDob']);
            assertContainsValue('Alex NoDob, Casey BadDob', $warningHtml);
            assertContainsValue('unknown or invalid DOB', $warningHtml);
        },
        'whitespace_only_dob_is_treated_as_unknown' => function () {
            $rows = [
                buildTestRow('Taylor', 'SpaceDob', ' ', 'Woden Athletics', '100m', 'SS #1'),
            ];

            $athletes = groupResultsByAthlete($rows);
            $warnings = collectUnknownDobAthleteNames($athletes);

            assertSameValue(['Taylor SpaceDob'], $warnings);
        },
        'season_bests_follow_meet_date_and_require_strict_time_improvements' => function () {
            $rows = [
                buildTestRow('Taylor', 'Runner', '11/28/2008', 'Woden Athletics', '100m', 'SS #2', 16, 'Male', 11.0),
                buildTestRow('Taylor', 'Runner', '11/28/2008', 'Woden Athletics', '100m', 'SS #1', 16, 'Male', 12.0),
                buildTestRow('Taylor', 'Runner', '11/28/2008', 'Woden Athletics', '100m', 'SS #3', 16, 'Male', 11.0),
                buildTestRow('Taylor', 'Runner', '11/28/2008', 'Woden Athletics', '100m', 'SS #4', 16, 'Male', 10.5),
            ];
            $dates = ['2026-02-12', '2026-02-05', '2026-02-19', '2026-02-26'];
            foreach ($rows as $index => $row) {
                $rows[$index]['meet_date_ts'] = strtotime($dates[$index]);
            }

            $athlete = groupResultsByAthlete($rows)['Taylor Runner|2008-11-28'];
            $summary = buildAthleteEventSummary($athlete, '100m', $athlete['events']['100m'], [
                ['name' => 'SS #1', 'events' => ['100m']],
                ['name' => 'SS #2', 'events' => ['100m']],
                ['name' => 'SS #3', 'events' => ['100m']],
                ['name' => 'SS #4', 'events' => ['100m']],
            ]);

            assertSameValue(['SS #1', 'SS #2', 'SS #3', 'SS #4'], array_column($summary['meets'], 'name'));
            assertSameValue([false, true, false, true], array_column($summary['meets'], 'is_season_best'));
            assertSameValue(2, $summary['sb_count']);
        },
        'season_bests_count_strict_field_improvements' => function () {
            $athlete = ['age' => 16];
            $results = [
                buildTestRow('Taylor', 'Jumper', '11/28/2008', 'Woden Athletics', 'Long Jump', 'SS #1', 16, 'Male', 4.0),
                buildTestRow('Taylor', 'Jumper', '11/28/2008', 'Woden Athletics', 'Long Jump', 'SS #2', 16, 'Male', 4.5),
                buildTestRow('Taylor', 'Jumper', '11/28/2008', 'Woden Athletics', 'Long Jump', 'SS #3', 16, 'Male', 4.5),
            ];
            $summary = buildAthleteEventSummary($athlete, 'Long Jump', $results, [
                ['name' => 'SS #1', 'events' => ['Long Jump']],
                ['name' => 'SS #2', 'events' => ['Long Jump']],
                ['name' => 'SS #3', 'events' => ['Long Jump']],
            ]);

            assertSameValue([false, true, false], array_column($summary['meets'], 'is_season_best'));
            assertSameValue(1, $summary['sb_count']);
        },
        'render_athlete_event_summary_displays_season_best_count_and_star' => function () {
            $html = renderAthleteEventSummary([
                'event' => '100m',
                'meets' => [[
                    'name' => 'SS #2', 'result_str' => '11.00', 'score_data' => ['score' => 700, 'status' => 'ok', 'meta' => []],
                    'participation_score' => 1, 'has_missing_record' => false, 'is_season_best' => true,
                ]],
                'best_score' => 700, 'participation_score' => 1, 'final_score' => 700, 'sb_count' => 1,
            ]);

            assertContainsValue('⭐', $html);
            assertContainsValue('[PF=1.00] ⭐', $html);
            assertContainsValue('<br><strong>SB:</strong> 1', $html);
            assertContainsValue('<span class="act-tooltip-trigger"', $html);
            assertNotContainsValue('act-score-btn', $html);
        },
        'render_athlete_scores_table_limits_to_top_twenty_and_adds_show_all_link' => function () {
            $athletes = [];

            for ($i = 1; $i <= 21; $i++) {
                $name = sprintf('Athlete %02d', $i);
                $athletes[$name] = [
                    'display_name' => $name,
                    'clubs' => ['Club ' . $i],
                    'club' => 'Club ' . $i,
                    'meet_pf' => 1,
                    'score' => 200 - $i,
                ];
            }

            $html = renderAthleteScoresTable($athletes, false, false, ['athletes' => '1']);

            assertContainsValue('Athlete 01', $html);
            assertContainsValue('Athlete 20', $html);
            assertNotContainsValue('Athlete 21', $html);
            assertContainsValue('<th style="width:50px"></th><th>Name</th><th style="text-align:right">Age</th>', $html);
            assertContainsValue('>1<', $html);
            assertContainsValue('>20<', $html);
            assertContainsValue('Show all', $html);
            assertContainsValue('?athletes=1&amp;all_athletes=1', $html);
            assertContainsValue('Meet PF', $html);
            assertContainsValue('Total athlete score after combining the counted event scores for this view.', $html);
        },
        'render_athlete_scores_table_show_all_link_preserves_current_query_state' => function () {
            $athletes = [];

            for ($i = 1; $i <= 21; $i++) {
                $name = sprintf('Athlete %02d', $i);
                $athletes[$name] = [
                    'display_name' => $name,
                    'clubs' => ['Club ' . $i],
                    'club' => 'Club ' . $i,
                    'meet_pf' => 1,
                    'score' => 200 - $i,
                ];
            }

            $html = renderAthleteScoresTable($athletes, false, false, [
                'comp' => 'hn',
                'verbose' => '1',
                'records' => '1',
            ]);

            assertContainsValue('?comp=hn&amp;verbose=1&amp;records=1&amp;all_athletes=1', $html);
        },
        'render_athlete_scores_table_sorts_by_sb_and_preserves_selected_sort' => function () {
            $athletes = [
                'Alex Example' => ['display_name' => 'Alex Example', 'age' => 16, 'clubs' => ['Woden Athletics'], 'meet_pf' => 1, 'score' => 150, 'sb_count' => 2],
                'Bailey Example' => ['display_name' => 'Bailey Example', 'age' => 17, 'clubs' => ['Woden Athletics'], 'meet_pf' => 1, 'score' => 160, 'sb_count' => 2],
                'Casey Example' => ['display_name' => 'Casey Example', 'age' => 18, 'clubs' => ['Woden Athletics'], 'meet_pf' => 1, 'score' => 200, 'sb_count' => 1],
            ];

            $html = renderAthleteScoresTable($athletes, false, true, ['athletes' => '1', 'sort' => 'sb'], 'sb');

            assertTrueValue(strpos($html, 'Bailey Example') < strpos($html, 'Alex Example'), 'Expected score to break SB ties');
            assertContainsValue('?athletes=1&amp;sort=score&amp;all_athletes=1', $html);
            assertContainsValue('?athletes=1&amp;sort=sb&amp;all_athletes=1', $html);
            assertContainsValue('>SB ↓</a>', $html);
            assertNotContainsValue('>Score ↓</a>', $html);
            assertNotContainsValue('<select name="sort"', $html);
            assertContainsValue('<th style="text-align:right">Age</th>', $html);
            assertContainsValue('>17</td>', $html);
        },
        'render_athlete_scores_table_uses_competition_places_for_tied_scores' => function () {
            $athletes = [
                'Alex Example' => [
                    'display_name' => 'Alex Example',
                    'clubs' => ['Woden Athletics'],
                    'club' => 'Woden Athletics',
                    'meet_pf' => 1,
                    'score' => 150,
                ],
                'Bailey Example' => [
                    'display_name' => 'Bailey Example',
                    'clubs' => ['Canberra Runners'],
                    'club' => 'Canberra Runners',
                    'meet_pf' => 0.9,
                    'score' => 140,
                ],
                'Casey Example' => [
                    'display_name' => 'Casey Example',
                    'clubs' => ['Canberra Runners'],
                    'club' => 'Canberra Runners',
                    'meet_pf' => 0.8,
                    'score' => 140,
                ],
                'Dakota Example' => [
                    'display_name' => 'Dakota Example',
                    'clubs' => ['Gungahlin Athletics'],
                    'club' => 'Gungahlin Athletics',
                    'meet_pf' => 0.7,
                    'score' => 130,
                ],
            ];

            $html = renderAthleteScoresTable($athletes, false, true, ['athletes' => '1']);

            assertContainsValue('>1<', $html);
            assertContainsValue('>2<', $html);
            assertContainsValue('>4<', $html);
            assertSameValue(2, substr_count($html, '<td style="text-align:right">2</td>'));
            assertNotContainsValue('<td style="text-align:right">3</td>', $html);
        },
        'render_athlete_scores_table_show_all_hides_link_and_omits_club_column_for_filtered_view' => function () {
            $athletes = [
                'Alex Example' => [
                    'display_name' => 'Alex Example',
                    'clubs' => ['Woden Athletics'],
                    'club' => 'Woden Athletics',
                    'meet_pf' => 0.5,
                    'score' => 123,
                ],
            ];

            $html = renderAthleteScoresTable($athletes, 'Woden Athletics', true, ['club' => 'Woden Athletics']);

            assertNotContainsValue('<th>Club</th>', $html);
            assertNotContainsValue('Show all', $html);
            assertContainsValue('0.50', $html);
            assertContainsValue('123', $html);
        },
        'render_club_scores_table_outputs_new_columns_and_tooltips' => function () {
            $clubs = [
                'Woden Athletics' => [
                    'cpf' => 0.625,
                    'score' => 200,
                    'adj' => 125,
                    'officials' => 40,
                    'total' => 165,
                ],
                'Canberra Runners' => [
                    'cpf' => 0.5,
                    'score' => 100,
                    'adj' => 50,
                    'officials' => 20,
                    'total' => 165,
                ],
                'Gungahlin Athletics' => [
                    'cpf' => 0.4,
                    'score' => 90,
                    'adj' => 36,
                    'officials' => 20,
                    'total' => 56,
                ],
            ];

            $html = renderClubScoresTable($clubs);

            assertContainsValue('>Club scores<', $html);
            assertContainsValue('<th style="width:50px"></th>', $html);
            assertContainsValue('Average athlete meet PF for the club', $html);
            assertContainsValue('Club adjusted score before officials: Score × CPF.', $html);
            assertContainsValue('Final club total: Adj + Officials.', $html);
            assertContainsValue('>1<', $html);
            assertContainsValue('>3<', $html);
            assertSameValue(2, substr_count($html, '<td style="text-align:right">1</td>'));
            assertNotContainsValue('<td style="text-align:right">2</td>', $html);
            assertContainsValue('0.625', $html);
            assertContainsValue('>125<', $html);
            assertContainsValue('>40<', $html);
            assertContainsValue('>165<', $html);
            assertNotContainsValue('CPF old', $html);
        },
        'render_view_toggles_disables_athletes_on_club_filter_and_preserves_non_default_query_params' => function () {
            $html = renderViewToggles(
                ['comp' => 'winter', 'records' => '1'],
                true,
                true,
                'Woden Athletics',
                ['ACT Masters Athletics', 'Woden Athletics'],
                [['season' => '2025-26', 'comp' => 'Summer Series'], ['season' => '2026-27', 'comp' => 'High Noon']],
                '2026-27',
                'High Noon'
            );

            assertNotContainsValue('name="comp" value="winter"', $html);
            assertContainsValue('name="records" value="1"', $html);
            assertContainsValue('Data set <select name="dataset"', $html);
            assertContainsValue('<option value="2026-27/High Noon" selected>2026-27 / High Noon</option>', $html);
            assertContainsValue('name="athletes" value="1" checked disabled', $html);
            assertNotContainsValue('toggle-fallback', $html);
            assertContainsValue('<option value="Woden Athletics" selected>', $html);
            assertNotContainsValue('name="verbose" value="1" type="hidden"', $html);
            assertNotContainsValue('type="hidden" name="club"', $html);
        },
        'render_view_toggles_adds_athlete_fallback_when_no_club_filter' => function () {
            $html = renderViewToggles([], false, false, false, ['Woden Athletics']);

            assertContainsValue('name="athletes" value="0" class="toggle-fallback"', $html);
            assertContainsValue('Show athlete scores', $html);
            assertContainsValue('<option value="">All clubs</option>', $html);
            assertNotContainsValue('disabled', $html);
        },
        'normalise_competition_key_rejects_invalid_or_unknown_values' => function () {
            assertSameValue('Summer Series', normaliseCompetitionKey('../reference', '2025-26'));
            assertSameValue('Summer Series', normaliseCompetitionKey('does-not-exist', '2025-26'));
            assertSameValue('High Noon', normaliseCompetitionKey('High Noon', '2025-26'));
            assertSameValue('High Noon', normaliseCompetitionKey('High Noon', '2026-27'));
        },
        'competition_keys_allow_readable_folder_names_but_not_path_characters' => function () {
            assertTrueValue(isValidCompetitionKey('Summer Series'), 'Expected readable competition folder names to be valid');
            assertTrueValue(isValidCompetitionKey('High-Noon_2026'), 'Expected hyphenated and underscored competition folder names to be valid');
            assertTrueValue(!isValidCompetitionKey('../reference'), 'Expected path traversal characters to be rejected');
            assertTrueValue(!isValidCompetitionKey('Summer/Series'), 'Expected path separators to be rejected');
        },
        'normalise_meet_name_preserves_competition_name_casing' => function () {
            assertSameValue('Summer Series #1', normaliseMeetName('/tmp/1.csv', 'Summer Series'));
        },
        'normalise_season_key_rejects_invalid_or_unknown_values' => function () {
            assertSameValue('2026-27', normaliseSeasonKey('../reference'));
            assertSameValue('2026-27', normaliseSeasonKey('2027-28'));
            assertSameValue('2026-27', normaliseSeasonKey('2026-27'));
        },
        'available_competition_datasets_includes_each_season_and_competition' => function () {
            assertSameValue([
                ['season' => '2025-26', 'comp' => 'High Noon'],
                ['season' => '2025-26', 'comp' => 'Summer Series'],
                ['season' => '2026-27', 'comp' => 'High Noon'],
                ['season' => '2026-27', 'comp' => 'Summer Series'],
            ], availableCompetitionDatasets());
            assertSameValue(['season' => '2026-27', 'comp' => 'Summer Series'], defaultCompetitionDataset());
            assertTrueValue(in_array(['season' => '2025-26', 'comp' => 'Summer Series'], availableCompetitionDatasets(), true), 'Expected the Summer Series dataset');
            assertTrueValue(in_array(['season' => '2026-27', 'comp' => 'High Noon'], availableCompetitionDatasets(), true), 'Expected the new High Noon dataset');
        },
        'load_competition_files_in_club_view_intentionally_excludes_champs_files' => function () {
            $files = array_values(loadCompetitionFiles('2025-26', 'Summer Series', 'Woden Athletics'));

            assertContainsValue('/data/2025-26/Summer Series/1.csv', $files[0] ?? '');
            assertTrueValue(!in_array('/Volumes/www/peek.net.au/dev/scoring/data/2025-26/Summer Series/8-u20-open.csv', $files, true), 'Expected club-filtered files to exclude U20/Open champs');
            assertTrueValue(!in_array('/Volumes/www/peek.net.au/dev/scoring/data/2025-26/Summer Series/9-u9-18.csv', $files, true), 'Expected club-filtered files to exclude U9-U18 champs');
        },
        'local_ss_summary_snapshot_matches_expected_values' => function () {
            $files = loadCompetitionFiles('2025-26', 'Summer Series', false);
            $resultData = filterExcludedEvents(loadCompetitionResults($files, 'Summer Series'));
            $meetEventArray = buildMeetEventArray($resultData);
            $athletes = groupResultsByAthlete($resultData);
            $summary = buildAthleteSummaries($athletes, [], $meetEventArray, false);
            $athletes = sortAthletesByScore($summary['athletes']);

            $snapshot = [
                'joshua_2008' => [
                    'score' => $athletes['Joshua Smith|2008-11-28']['score'] ?? null,
                    'meet_pf' => scoreFormatNumber((float)($athletes['Joshua Smith|2008-11-28']['meet_pf'] ?? 0), 6),
                    'attended' => $athletes['Joshua Smith|2008-11-28']['attended_meet_count'] ?? null,
                    'eligible' => $athletes['Joshua Smith|2008-11-28']['eligible_meet_count'] ?? null,
                ],
                'lucas_butler' => [
                    'score' => $athletes['Lucas Butler|2010-07-18']['score'] ?? null,
                    'meet_pf' => scoreFormatNumber((float)($athletes['Lucas Butler|2010-07-18']['meet_pf'] ?? 0), 6),
                    'attended' => $athletes['Lucas Butler|2010-07-18']['attended_meet_count'] ?? null,
                    'eligible' => $athletes['Lucas Butler|2010-07-18']['eligible_meet_count'] ?? null,
                ],
            ];

            assertSameValue([
                'joshua_2008' => [
                    'score' => 442.0,
                    'meet_pf' => '0.076923',
                    'attended' => 1,
                    'eligible' => 13,
                ],
                'lucas_butler' => [
                    'score' => 1648.0,
                    'meet_pf' => '0.769231',
                    'attended' => 10,
                    'eligible' => 13,
                ],
            ], $snapshot);
        },
        'local_ss_data_has_three_joshua_smith_identities_after_normalisation' => function () {
            $files = loadCompetitionFiles('2025-26', 'Summer Series', false);
            $resultData = loadCompetitionResults($files, 'Summer Series');
            $resultData = filterExcludedEvents($resultData);
            $athletes = groupResultsByAthlete($resultData);

            $joshuaSmithKeys = array_values(array_filter(array_keys($athletes), function ($key) {
                return str_starts_with($key, 'Joshua Smith|');
            }));

            sort($joshuaSmithKeys);

            assertSameValue([
                'Joshua Smith|2002-05-08-amb',
                'Joshua Smith|2008-11-28',
                'Joshua Smith|2009-01-12-amb',
            ], $joshuaSmithKeys);
        },
    ];
}

function buildTestRow($first, $last, $dobRaw, $club, $event, $meet, $age = 16, $gender = 'Male', $resultRaw = 12.34) {
    $dobRaw = trim((string)$dobRaw);
    $dobTimestamp = $dobRaw !== '' ? strtotime($dobRaw) : false;

    return [
        'firstname' => $first,
        'lastname' => $last,
        'dob_raw' => $dobRaw,
        'dob' => $dobTimestamp !== false ? $dobTimestamp : null,
        'club' => $club,
        'event' => $event,
        'meet' => $meet,
        'age' => $age,
        'gender' => $gender,
        'result_raw' => $resultRaw,
        'result_str' => (string)$resultRaw,
        'is_para' => false,
        'meet_date' => null,
        'meet_date_ts' => null,
    ];
}

function assertSameValue($expected, $actual) {
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertCountValue($expectedCount, $value) {
    $actualCount = count($value);
    if ($actualCount !== $expectedCount) {
        throw new RuntimeException("Expected count {$expectedCount}, got {$actualCount}");
    }
}

function assertTrueValue($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertContainsValue($needle, $haystack) {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('Expected to find ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

function assertNotContainsValue($needle, $haystack) {
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException('Did not expect to find ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}
