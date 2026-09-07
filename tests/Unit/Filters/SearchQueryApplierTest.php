<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use CodeIgniter\Database\BaseBuilder;
use dcardenasl\Ci4ApiCore\Filters\SearchProfile;
use dcardenasl\Ci4ApiCore\Filters\SearchQueryApplier;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lean smoke coverage for the LIKE-search path (the FULLTEXT path needs a
 * live MySQL connection — exercised by consumer-side integration tests).
 * Without a CI4 host, `function_exists('config')` is false, so the
 * `config('Api')` lookups fall back to safe defaults and the search runs.
 *
 * @internal
 */
final class SearchQueryApplierTest extends TestCase
{
    public function testApplyIsNoOpWhenSearchableFieldsEmpty(): void
    {
        $builder = $this->createMock(BaseBuilder::class);
        $builder->expects($this->never())->method('groupStart');
        $builder->expects($this->never())->method('like');

        SearchQueryApplier::apply($builder, 'whatever', [], false);
    }

    public function testApplyIsNoOpWhenQueryEmpty(): void
    {
        $builder = $this->createMock(BaseBuilder::class);
        $builder->expects($this->never())->method('groupStart');

        SearchQueryApplier::apply($builder, '', ['name'], false);
    }

    public function testApplyLikePathRunsWithSafeDefaultsWhenConfigApiAbsent(): void
    {
        // No CI4 host, no `config('Api')` — the helper coalesces to defaults
        // and search runs (searchEnabled=true, minLength=0).
        $builder = $this->createMock(BaseBuilder::class);
        $builder->expects($this->once())->method('groupStart')->willReturnSelf();
        $builder->expects($this->once())->method('like')->with('name', 'foo')->willReturnSelf();
        $builder->expects($this->once())->method('orLike')->with('email', 'foo')->willReturnSelf();
        $builder->expects($this->once())->method('groupEnd')->willReturnSelf();

        SearchQueryApplier::apply($builder, 'foo', ['name', 'email'], false);
    }

    public function testApplyLikeEmitsSingleLikeForSingleField(): void
    {
        $builder = $this->createMock(BaseBuilder::class);
        $builder->expects($this->once())->method('groupStart')->willReturnSelf();
        $builder->expects($this->once())->method('like')->with('name', 'bar')->willReturnSelf();
        $builder->expects($this->never())->method('orLike');
        $builder->expects($this->once())->method('groupEnd')->willReturnSelf();

        SearchQueryApplier::applyLike($builder, 'bar', ['name']);
    }

    #[DataProvider('booleanModeOperatorProvider')]
    public function testSanitizeFulltextQueryStripsOperators(string $input, string $expected): void
    {
        $this->assertSame($expected, SearchQueryApplier::sanitizeFulltextQuery($input));
    }

    /** @return array<string, array{string, string}> */
    public static function booleanModeOperatorProvider(): array
    {
        return [
            'plus operator'          => ['+word', ' word'],
            'minus operator'         => ['-word', ' word'],
            'wildcard star'          => ['word*', 'word '],
            'phrase quotes'          => ['"exact phrase"', ' exact phrase '],
            'parentheses'            => ['(a OR b)', ' a OR b '],
            'tilde operator'         => ['~noise', ' noise'],
            'greater than'           => ['>important', ' important'],
            'less than'              => ['<less', ' less'],
            'combined operators'     => ['+best -worst', ' best  worst'],
            'plain query unchanged'  => ['hello world', 'hello world'],
            // `@` opens Boolean Mode's @distance operator, so an ordinary
            // email address was a fatal syntax error and the request 500'd.
            'at sign'                => ['admin@example.com', 'admin example.com'],
        ];
    }
    public function testProfileAppliesEachBucketInsideOneGroup(): void
    {
        // Every bucket must land in a single group, or the search widens a
        // constraint the caller already applied.
        $builder = $this->createMock(BaseBuilder::class);
        $builder->expects($this->once())->method('groupStart')->willReturnSelf();
        $builder->expects($this->once())->method('groupEnd')->willReturnSelf();
        $builder->expects($this->once())->method('like')->with('email', 'foo')->willReturnSelf();
        $builder->expects($this->once())->method('orLike')->with('code', 'foo', 'after')->willReturnSelf();
        $builder->expects($this->once())->method('orWhere')->with('slug', 'foo')->willReturnSelf();

        SearchQueryApplier::applyProfile(
            $builder,
            'foo',
            new SearchProfile(like: ['email'], prefix: ['code'], exact: ['slug']),
            'widgets',
        );
    }

    public function testProfileIsANoOpForAnEmptyQuery(): void
    {
        $builder = $this->createMock(BaseBuilder::class);
        $builder->expects($this->never())->method('groupStart');

        SearchQueryApplier::applyProfile($builder, '   ', new SearchProfile(like: ['name']), 'widgets');
    }

    public function testProfileMustDeclareAtLeastOneColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SearchProfile();
    }

    public function testProfileRejectsColumnsOutsideTheModelWhitelist(): void
    {
        $profile = new SearchProfile(fulltext: ['name'], like: ['password_hash']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('password_hash');

        $profile->assertWhitelisted(['name', 'email']);
    }

    public function testProfileAcceptsWhitelistedColumnsAndKeepsBucketOrder(): void
    {
        $profile = new SearchProfile(fulltext: ['first_name', 'last_name'], like: ['email']);

        $profile->assertWhitelisted(['email', 'first_name', 'last_name']);

        $this->assertSame(['first_name', 'last_name', 'email'], $profile->columns());
    }

    public function testWithoutFulltextFoldsNaturalLanguageColumnsIntoLike(): void
    {
        // How the `searchUseFulltext` kill switch is honoured without a model
        // being able to override it.
        $profile = (new SearchProfile(fulltext: ['bio'], like: ['email'], prefix: ['code']))->withoutFulltext();

        $this->assertSame([], $profile->fulltext);
        $this->assertSame(['email', 'bio'], $profile->like);
        $this->assertSame(['code'], $profile->prefix);
    }

    public function testWithoutFulltextIsIdentityWhenThereIsNoFulltextBucket(): void
    {
        $profile = new SearchProfile(like: ['email']);

        $this->assertSame($profile, $profile->withoutFulltext());
    }

    public function testFulltextOnlyPreservesTheBehaviourOfModelsWithoutAProfile(): void
    {
        $profile = SearchProfile::fulltextOnly(['a', 'b']);

        $this->assertSame(['a', 'b'], $profile->fulltext);
        $this->assertSame([], $profile->like);
    }
    public function testWholeValueOnlyKeepsTheLookupBucketsAndDropsFreeText(): void
    {
        // `searchMinLength` exists to stop 1-2 character fragments matching
        // free text. It has nothing to say about a two-letter code searched in
        // a two-letter column, so a query below the floor keeps the
        // whole-value buckets and drops the rest.
        $profile = (new SearchProfile(
            fulltext: ['first_name'],
            like: ['email'],
            prefix: ['code'],
            exact: ['slug'],
        ))->wholeValueOnly();

        $this->assertNotNull($profile);
        $this->assertSame([], $profile->fulltext);
        $this->assertSame([], $profile->like);
        $this->assertSame(['code'], $profile->prefix);
        $this->assertSame(['slug'], $profile->exact);
    }

    public function testWholeValueOnlyIsNullForAPureFreeTextProfile(): void
    {
        // Nothing left to search below the floor, so the caller skips entirely
        // — the behaviour such a profile had before.
        $this->assertNull((new SearchProfile(fulltext: ['bio'], like: ['email']))->wholeValueOnly());
    }
}
