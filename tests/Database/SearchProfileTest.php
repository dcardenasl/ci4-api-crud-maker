<?php

declare(strict_types=1);

namespace Tests\Database;

use Config\Database;
use dcardenasl\Ci4ApiCore\Filters\FulltextIndexInspector;
use dcardenasl\Ci4ApiCore\Filters\SearchProfile;
use dcardenasl\Ci4ApiCore\Filters\SearchQueryApplier;
use Tests\Support\DatabaseTestCase;

/**
 * The failures this contract exists for, against a real MySQL engine.
 *
 * None of these can be unit tests: every one was the database rejecting the
 * statement or the index silently matching nothing.
 *
 * @internal
 */
final class SearchProfileTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->database->query('DROP TABLE IF EXISTS `search_people`');
        $this->database->query(
            'CREATE TABLE `search_people` ('
            . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `email` VARCHAR(255) NOT NULL,'
            . ' `first_name` VARCHAR(100) NOT NULL,'
            . ' `last_name` VARCHAR(100) NOT NULL,'
            . ' `code` VARCHAR(10) NOT NULL,'
            . ' PRIMARY KEY (`id`),'
            // Declared with the table, not added by a later ALTER: InnoDB
            // accepts an online ADD FULLTEXT on a populated table and can leave
            // the index present but empty, which turns every search into a
            // silent zero result.
            . ' FULLTEXT KEY `idx_people_names` (`first_name`, `last_name`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->database->table('search_people')->insert([
            'email' => 'admin@example.com', 'first_name' => 'Suite', 'last_name' => 'Smoke', 'code' => 'es',
        ]);

        FulltextIndexInspector::flushCache();
    }

    protected function tearDown(): void
    {
        FulltextIndexInspector::flushCache();
        $this->database->query('DROP TABLE IF EXISTS `search_people`');
        parent::tearDown();
    }

    public function testEmailSearchNoLongerReachesBooleanMode(): void
    {
        // The regression: MATCH(email, ...) AGAINST('admin@example.com') is a
        // syntax error, and it took a whole list screen down with it.
        $builder = $this->database->table('search_people');

        SearchQueryApplier::applyProfile(
            $builder,
            'admin@example.com',
            new SearchProfile(fulltext: ['first_name', 'last_name'], like: ['email']),
            'search_people',
        );

        $this->assertSame(1, $builder->countAllResults());
    }

    public function testUncoveredColumnListDegradesToLikeInsteadOfFailing(): void
    {
        // No index covers (email, first_name, last_name) here. MySQL would
        // reject the MATCH outright; the profile has to fall back instead.
        $builder = $this->database->table('search_people');

        SearchQueryApplier::applyProfile(
            $builder,
            'Suite',
            SearchProfile::fulltextOnly(['email', 'first_name', 'last_name']),
            'search_people',
        );

        $this->assertSame(1, $builder->countAllResults());
    }

    public function testCoveredColumnListTakesTheFulltextPathAndMatches(): void
    {
        $builder = $this->database->table('search_people');

        SearchQueryApplier::applyProfile(
            $builder,
            'Suite',
            new SearchProfile(fulltext: ['first_name', 'last_name']),
            'search_people',
        );

        $this->assertSame(1, $builder->countAllResults());
    }

    public function testPartialTermMatchesSoIncrementalFilteringWorks(): void
    {
        // FULLTEXT matches whole words; without the appended `*` a filter box
        // shows nothing until the final keystroke.
        $builder = $this->database->table('search_people');

        SearchQueryApplier::applyProfile(
            $builder,
            'Sui',
            new SearchProfile(fulltext: ['first_name', 'last_name']),
            'search_people',
        );

        $this->assertSame(1, $builder->countAllResults());
    }

    public function testShortCodeUsesPrefixBecauseItCannotMatchFulltext(): void
    {
        // "es" is below innodb_ft_min_token_size and would never match.
        $builder = $this->database->table('search_people');

        SearchQueryApplier::applyProfile(
            $builder,
            'es',
            new SearchProfile(prefix: ['code']),
            'search_people',
        );

        $this->assertSame(1, $builder->countAllResults());
    }

    public function testAnAllOperatorQueryNarrowsInsteadOfMatchingEverything(): void
    {
        // Sanitising "+-*()" to nothing would leave AGAINST(''), which matches
        // every row — the opposite of what a filter should do.
        $builder = $this->database->table('search_people');

        SearchQueryApplier::applyProfile(
            $builder,
            '+-*"()~<>@',
            new SearchProfile(fulltext: ['first_name', 'last_name']),
            'search_people',
        );

        $this->assertSame(0, $builder->countAllResults());
    }

    public function testSearchWithNoMatchReturnsNothingRatherThanFailing(): void
    {
        $builder = $this->database->table('search_people');

        SearchQueryApplier::applyProfile(
            $builder,
            'zzzz-no-match',
            new SearchProfile(fulltext: ['first_name', 'last_name'], like: ['email'], prefix: ['code']),
            'search_people',
        );

        $this->assertSame(0, $builder->countAllResults());
    }

    public function testInspectorRequiresAnExactColumnMatch(): void
    {
        $db = Database::connect();

        $this->assertTrue(FulltextIndexInspector::covers($db, 'search_people', ['first_name', 'last_name']));
        $this->assertTrue(FulltextIndexInspector::covers($db, 'search_people', ['last_name', 'first_name']));
        $this->assertFalse(FulltextIndexInspector::covers($db, 'search_people', ['first_name']));
        $this->assertFalse(FulltextIndexInspector::covers($db, 'search_people', ['email', 'first_name', 'last_name']));
    }

    public function testTheSearchGroupDoesNotWidenAnExistingConstraint(): void
    {
        $this->database->table('search_people')->insert([
            'email' => 'other@example.com', 'first_name' => 'Other', 'last_name' => 'Person', 'code' => 'en',
        ]);

        $builder = $this->database->table('search_people')->where('code', 'en');

        SearchQueryApplier::applyProfile(
            $builder,
            'Suite',
            new SearchProfile(fulltext: ['first_name', 'last_name'], like: ['email']),
            'search_people',
        );

        // "Suite" exists, but only under code=es — the caller's constraint wins.
        $this->assertSame(0, $builder->countAllResults());
    }
}
