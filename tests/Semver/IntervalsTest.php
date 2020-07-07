<?php

/*
 * This file is forked from composer/semver.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * https://github.com/composer/semver/blob/master/LICENSE
 */

namespace Symfony\Flex\Tests\Semver;

use PHPUnit\Framework\TestCase;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchNoneConstraint;
use Symfony\Flex\Semver\FullConstraint;
use Symfony\Flex\Semver\Interval;
use Symfony\Flex\Semver\Intervals;
use Symfony\Flex\Semver\VersionParser;

class IntervalsTest extends TestCase
{
    const INTERVAL_ANY = '*/dev*';
    const INTERVAL_ANY_NODEV = '*';
    const INTERVAL_NONE = '';

    const COMPACT_NONE = '';

    /**
     * @dataProvider compactProvider
     */
    public function testCompactConstraint($expected, $toCompact, $conjunctive)
    {
        $parser = new VersionParser;

        $parts = array();
        foreach ($toCompact as $part) {
            $parts[] = $parser->parseConstraints($part);
        }

        if ($expected === self::COMPACT_NONE) {
            $expected = class_exists(MatchNoneConstraint::class) ? new MatchNoneConstraint : new FullConstraint;
        } else {
            $expected = $parser->parseConstraints($expected);
        }

        $new = Intervals::compactConstraint(new MultiConstraint($parts, $conjunctive));
        $this->assertSame((string) $expected, (string) $new);
    }

    public function compactProvider()
    {
        return array(
            'simple disjunctive multi' => array(
                '1.0 - 1.2 || ^1.5',
                array('1.0 - 1.2 || ^1.5', '1.8 - 1.9 || ^1.12'),
                false
            ),
            'simple conjunctive multi' => array(
                '1.8 - 1.9 || ^1.12',
                array('1.0 - 1.2 || ^1.5', '1.8 - 1.9 || ^1.12'),
                true
            ),
            'dev constraints propagate, disjunctive' => array(
                '1.8 - 1.9 || ^1.12 || dev-master || dev-foo',
                array('1.8 - 1.9 || ^1.12', 'dev-master', 'dev-foo'),
                false
            ),
            'dev constraints + numeric constraint, conjunctive results in match-none' => array(
                self::COMPACT_NONE,
                array('1.8 - 1.9 || ^1.12', 'dev-master', 'dev-foo'),
                true
            ),
            'conflicting numeric constraint, conjunctive results in match-none' => array(
                self::COMPACT_NONE,
                array('1.0', '2.0'),
                true
            ),
            'simple disjunctive results in same output' => array(
                '1.0 || 2.0',
                array('1.0', '2.0'),
                false
            ),
            'simple conjunctive results in same output' => array(
                '!= 1.2, != 1.6',
                array('!= 1.2', '!= 1.6'),
                true
            ),
            'simple conjunctive results in same output/2' => array(
                '!= 1.0, != 2.0',
                array('!= 1.0', '!= 2.0'),
                true
            ),
            'switches to conjunctive if more than != x is present' => array(
                '>1.5, != 2.0',
                array('!= 2.0', '> 1.5'),
                true
            ),
            'complex conjunctive with dev' => array(
                '!= 1.0, != 2.0',
                array('!= 1.0', '!= 2.0'),
                true
            ),
            'simple disjunctive with negation' => array(
                '!= 1.0',
                array('!= 1.0', '!= 1.0'),
                false
            ),
            'disjunctive with complex negation' => array(
                '*',
                array('!= 1.0', '!= 1.0', '!= dev-foo', '1.0.5.*'),
                false
            ),
            'conjunctive with complex negation' => array(
                '1.0.5.*',
                array('!= 1.0', '!= 1.0', '!= dev-foo', '1.0.5.*'),
                true
            ),
            'conjunctive with complex negation/2' => array(
                '>= 1.0-dev, != 1.2-stable, <2',
                array('!= 1.2', '!= dev-foo', '!= dev-bar', '1.*'),
                true
            ),
            'conjunctive with complex negation/3' => array(
                '!= 1.2, != dev-foo, != dev-bar',
                array('!= 1.2', '!= dev-foo', '!= dev-bar'),
                true
            ),
            'disjunctive with complex negation/3' => array(
                '*',
                array('!= 1.2', '!= dev-foo', '!= dev-bar'),
                false
            ),
            'conjunctive with complex negation/4' => array(
                '== dev-foo',
                array('!= 1.2', '== dev-foo', '!= dev-bar'),
                true
            ),
            'disjunctive with complex negation and dev ==' => array(
                '*',
                array('!= 1.0', '!= 1.0', '!= dev-foo', '1.0.5.*', '== dev-bla'),
                false
            ),
            'conjunctive with complex negation and dev ==' => array(
                'dev-bla',
                array('!= 1.0', '!= 1.0', '!= dev-foo', '== dev-bla'),
                true
            ),
            'complex conjunctive which can not match anything' => array(
                self::COMPACT_NONE,
                array('!= 1.0', '!= 1.0', '!= dev-foo', '1.0.5.*', '== dev-bla'),
                true
            ),
            'conjunctive with more than one dev negation' => array(
                '!= dev-master, != dev-foo',
                array('!= dev-master', '!= dev-foo'),
                true
            ),
            'conjunctive with mix of devs' => array(
                '== dev-foo',
                array('!= dev-master', '== dev-foo'),
                true
            ),
            'disjunctive with mix of devs' => array(
                '!= dev-master',
                array('!= dev-master', '== dev-foo'),
                false
            ),
            'conjunctive with more than one dev negation, and numeric constraint' => array(
                '> 5',
                array('!= dev-master', '!= dev-foo', '> 5'),
                true
            ),
            'conjunctive with more than one of the same dev negation' => array(
                '!= dev-foo',
                array('!= dev-foo', '!= dev-foo'),
                true
            ),
            'switches to conjunctive when excluding versions and complex' => array(
                '!= 3-stable, <5 || >=6, <9',
                array('!= 3, <5', '>=6, <9'),
                false
            ),
            'conjunctive with multiple numeric negations and a disjunctive exact match for dev versions' => array(
                '== dev-foo || == dev-bar',
                array('!= 1.0', '!= 2.0', '==dev-foo || ==dev-bar'),
                true,
            ),
        );
    }

    /**
     * @dataProvider intervalsProvider
     */
    public function testGetIntervals($expected, $constraint)
    {
        if (is_string($constraint)) {
            $parser = new VersionParser;
            $constraint = $parser->parseConstraints($constraint);
        }

        $result = Intervals::get($constraint);
        if (is_array($result)) {
            array_walk_recursive($result, function (&$c) {
                if ($c instanceof Interval) {
                    $c = array('start' => (string) $c->getStart(), 'end' => (string) $c->getEnd());
                }
            });
        }

        if ($expected === self::INTERVAL_ANY) {
            $expected = array('numeric' => array(
                array(
                    'start' => '>= 0.0.0.0-dev',
                    'end' => '< '.PHP_INT_MAX.'.0.0.0',
                ),
            ), 'branches' => Interval::anyDev());
        }

        if ($expected === self::INTERVAL_ANY_NODEV) {
            $expected = array('numeric' => array(
                array(
                    'start' => '>= 0.0.0.0-dev',
                    'end' => '< '.PHP_INT_MAX.'.0.0.0',
                ),
            ), 'branches' => Interval::noDev());
        }

        if ($expected === self::INTERVAL_NONE) {
            $expected = array('numeric' => array(), 'branches' => Interval::noDev());
        }

        $this->assertSame($expected, $result);
    }

    public function intervalsProvider()
    {
        return array(
            'simple case' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.0.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '^1.0'
            ),
            'simple case/2' => array(
                array('numeric' => array(
                    array(
                        'start' => '> 1.0.0.0',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => Interval::noDev()),
                '> 1.0'
            ),
            'intervals should be sorted' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.9.0.0-dev',
                        'end' => '< 1.0.0.0-dev',
                    ),
                    array(
                        'start' => '>= 1.2.3.0',
                        'end' => '<= 1.2.3.0',
                    ),
                    array(
                        'start' => '>= 1.3.4.0',
                        'end' => '<= 1.3.4.0',
                    ),
                    array(
                        'start' => '> 2.3.0.0',
                        'end' => '< 2.5.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '1.3.4 || 1.2.3 || >2.3,<2.5 || <1,>=0.9'
            ),
            'intervals should be sorted and consecutive ones merged' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.0.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                    array(
                        'start' => '>= 3.0.0.0-dev',
                        'end' => '< 5.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '^4.0 || ^1.0 || ^3.0'
            ),
            'consecutive intervals should be merged even if one has no end' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 4.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => Interval::noDev()),
                '^4.0 || >= 5'
            ),
            'consecutive intervals should be merged even if one has no start' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< 6.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '>= 5,< 6 || < 5'
            ),
            'consecutive intervals representing everything should become *' => array(
                self::INTERVAL_ANY_NODEV,
                '>= 5 || < 5'
            ),
            'intervals should be sorted and overlapping ones merged' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.1.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                    array(
                        'start' => '>= 3.0.0.0-dev',
                        'end' => '< 5.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '^4.0 || ^1.1 || ^3.0 || ^1.2'
            ),
            'intervals should be sorted and overlapping ones merged/2' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.0.0.0-dev',
                        'end' => '< 1.5.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '1.2 - 1.4 || 1.0 - 1.3'
            ),
            'overlapping intervals should be merged even if the last has no end' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 4.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => Interval::noDev()),
                '^4.0 || >= 4.5'
            ),
            'overlapping intervals should be merged even if the first has no start' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< 6.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '>= 5,< 6 || < 5.3'
            ),
            'overlapping intervals representing everything should become *' => array(
                self::INTERVAL_ANY_NODEV,
                '>= 5 || <= 5'
            ),
            'equal intervals should be merged' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.0.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '^1.0 || ^1.0'
            ),
            'weird input order should still be a good result' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '< 2.0 || < 1.2'
            ),
            'weird input order should still be a good result, matches everything' => array(
                self::INTERVAL_ANY_NODEV,
                '< 2.0 || >= 1'
            ),
            'weird input order should still be a good result, conjunctive' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.0.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '< 2.0, >= 1'
            ),
            'conjunctive constraints result in no interval if conflicting' => array(
                self::INTERVAL_NONE,
                '^1.0, ^2.0'
            ),
            'conjunctive constraints result in no interval if conflicting/2' => array(
                self::INTERVAL_NONE,
                '^1.0, ^3.0'
            ),
            'conjunctive constraints result in no interval if conflicting/3' => array(
                self::INTERVAL_NONE,
                '== 1.0, != 1.0'
            ),
            'conjunctive constraints result in no interval if conflicting/4' => array(
                self::INTERVAL_NONE,
                '> 1.0, dev-master'
            ),
            'conjunctive constraints result in no branches interval if numeric is provided' => array(
                array('numeric' => array(
                    array(
                        'start' => '> 5.0.0.0',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => Interval::noDev()),
                '!= dev-master, != dev-foo, > 5'
            ),
            'conjunctive constraints result in no branches interval if numeric is provided, even if one matches dev*' => array(
                array('numeric' => array(
                    array(
                        'start' => '> 5.0.0.0',
                        'end' => '< 6.0.0.0',
                    ),
                    array(
                        'start' => '> 6.0.0.0',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => Interval::noDev()),
                '!= 6, > 5'
            ),
            'disjunctive constraints keeps branch intervals if numeric is provided' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-master', 'dev-foo'), 'exclude' => true)),
                '!= dev-master, != dev-foo || > 5'
            ),
            'conjunctive constraints should be intersected' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.2.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '^1.0, ^1.2'
            ),
            'conjunctive constraints should be intersected/2' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.5.0.0-dev',
                        'end' => '< 1.7.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '^1.0, ^1.2, 1.4 - 1.8, 1.5 - 1.6, 1.5 - 2'
            ),
            'conjunctive constraints should be intersected, not flattened by version parser' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.5.0.0-dev',
                        'end' => '< 1.7.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                new MultiConstraint(array(
                    new MultiConstraint(array(
                        new Constraint('>=', '1.0.0.0-dev'),
                        new Constraint('<', '2.0.0.0-dev'),
                    ), true),
                    new MultiConstraint(array(
                        new Constraint('>=', '1.2.0.0-dev'),
                        new Constraint('<', '2.0.0.0-dev'),
                    ), true),
                    new MultiConstraint(array(
                        new Constraint('>=', '1.4.0.0-dev'),
                        new Constraint('<', '1.9.0.0-dev'),
                    ), true),
                    new MultiConstraint(array(
                        new Constraint('>=', '1.5.0.0-dev'),
                        new Constraint('<', '1.7.0.0-dev'),
                    ), true),
                    new MultiConstraint(array(
                        new Constraint('>=', '1.5.0.0-dev'),
                        new Constraint('<', '3.0.0.0-dev'),
                    ), true),
                ), true),
            ),
            'conjunctive constraints with disjunctive subcomponents should be intersected, not flattened by version parser' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.8.0.0-dev',
                        'end' => '< 1.10.0.0-dev',
                    ),
                    array(
                        'start' => '>= 1.12.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                new MultiConstraint(array(
                    new MultiConstraint(array( // 1.0 - 1.2 || ^1.5
                        new MultiConstraint(array(
                            new Constraint('>=', '1.0.0.0-dev'),
                            new Constraint('<', '1.3.0.0-dev'),
                        ), true),
                        new MultiConstraint(array(
                            new Constraint('>=', '1.5.0.0-dev'),
                            new Constraint('<', '2.0.0.0-dev'),
                        ), true),
                    ), false),
                    new MultiConstraint(array( // 1.8 - 1.9 || ^1.12
                        new MultiConstraint(array(
                            new Constraint('>=', '1.8.0.0-dev'),
                            new Constraint('<', '1.10.0.0-dev'),
                        ), true),
                        new MultiConstraint(array(
                            new Constraint('>=', '1.12.0.0-dev'),
                            new Constraint('<', '2.0.0.0-dev'),
                        ), true),
                    ), false),
                ), true),
            ),
            'conjunctive constraints with equal constraints' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.3.2.0-dev',
                        'end' => '<= 1.3.2.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                new MultiConstraint(array(
                    new MultiConstraint(array(
                        new Constraint('==', '1.3.1.0-dev'),
                        new Constraint('==', '1.3.2.0-dev'),
                        new Constraint('==', '1.3.3.0-dev'),
                    ), false),
                    new Constraint('==', '1.3.2.0-dev'),
                ), true),
            ),
            'conjunctive constraints simple' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.5.0.0-dev',
                        'end' => '< 3.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '1.5 - 2'
            ),
            'conjunctive constraints with dev exclusions' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.0.0.0-dev',
                        'end' => '< 1.2.3.0',
                    ),
                    array(
                        'start' => '> 1.2.3.0',
                        'end' => '< 1.4.5.0',
                    ),
                    array(
                        'start' => '> 1.4.5.0',
                        'end' => '< 2.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '!= 1.4.5, ^1.0, != 1.2.3, != 2.3, != dev-foo, != dev-master'
            ),
            'conjunctive constraints with dev exact versions suppresses the number scope matches' => array(
                self::INTERVAL_NONE,
                '!= 1.4.5, ^1.0, != 1.2.3, != 2.3, == dev-foo, == dev-foo'
            ),
            'conjunctive constraints with dev exact versions suppresses the number scope matches, but keeps dev- match if number constraints allowed dev*' => array(
                array('numeric' => array(
                ), 'branches' => array('names' => array('dev-foo'), 'exclude' => false)),
                '!= 1.2.3, != 2.3, == dev-foo, == dev-foo'
            ),
            'disjunctive constraints with exclusions in dev constraints makes the number scope match *' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-foo'), 'exclude' => true)),
                '^1.0 || != dev-foo'
            ),
            'disjunctive constraints with exclusions in dev constraints makes number scope match *' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-foo'), 'exclude' => true)),
                '^1.0 || != dev-foo'
            ),
            'disjunctive constraints with exclusions, if matches * in number scope and dev scope, then * is returned' => array(
                self::INTERVAL_ANY,
                '!= 1.4.5 || ^1.0 || != dev-foo || != dev-master || == dev-master'
            ),
            'disjunctive constraints with exclusions, if dev constraints match *, then * is returned for everything' => array(
                self::INTERVAL_ANY,
                '^1.0 || != dev-master || == dev-master'
            ),
            'disjunctive constraints with exclusions, if dev constraints match * except in dev scope, then * is returned for number scope' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-foo'), 'exclude' => true)),
                '^1.0 || != dev-foo || == dev-master'
            ),
            'disjunctive constraints with exact dev matches returns number scope as it should and unique dev constraints' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.0.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                ), 'branches' => array('names' => array('dev-foo', 'dev-master'), 'exclude' => false)),
                '^1.0 || == dev-foo || == dev-master || == dev-master'
            ),
            'conjunctive constraints with exact versions' => array(
                self::INTERVAL_NONE,
                'dev-master, ^1.0'
            ),
            'conjunctive constraints with exact versions, dev only, diff version should result in no interval and no constraints' => array(
                self::INTERVAL_NONE,
                'dev-master, dev-foo'
            ),
            'conjunctive constraints with exact versions, dev only, same version should pass through' => array(
                array('numeric' => array(), 'branches' => array('names' => array('dev-master'), 'exclude' => false)),
                'dev-master, dev-master'
            ),
            'conjunctive constraints with same dev exclusion, should result in * with dev exclusion' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-master'), 'exclude' => true)),
                '!= dev-master, != dev-master'
            ),
            'conjunctive constraints with different dev exclusion, should result in * with dev exclusions' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-master', 'dev-foo'), 'exclude' => true)),
                '!= dev-master, != dev-foo'
            ),
            'disjunctive constraints with exact versions' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.0.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                ), 'branches' => array('names' => array('dev-master', 'dev-foo'), 'exclude' => false)),
                'dev-master || ^1.0 || dev-foo || dev-master'
            ),
            'conjunctive constraints with * should skip it' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 1.0.0.0-dev',
                        'end' => '< 2.0.0.0-dev',
                    ),
                ), 'branches' => Interval::noDev()),
                '^1.0, *'
            ),
            'disjunctive constraints with * should result in *' => array(
                self::INTERVAL_ANY,
                '^1.0 || *'
            ),
            'conjunctive constraints with only * should result in *' => array(
                self::INTERVAL_ANY,
                '*, *'
            ),
            'conjunctive constraints equivalent of * should result in *' => array(
                self::INTERVAL_ANY_NODEV,
                new MultiConstraint(array(new Constraint('>=', '0.0.0.0-dev'), new Constraint('<', PHP_INT_MAX.'.0.0.0'))),
            ),
            'disjunctive constraints with * and dev exclusion should not return the dev exclusion' => array(
                self::INTERVAL_ANY,
                '!= dev-foo || *'
            ),
            'conjunctive constraints with various dev constraints/2' => array(
                array('numeric' => array(
                    array(
                        'start' => '> 5.0.0.0',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => Interval::noDev()),
                '> 5, *'
            ),
            'conjunctive constraints with various dev constraints/3' => array(
                array('numeric' => array(
                    array(
                        'start' => '> 5.0.0.0',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => Interval::noDev()),
                '!= dev-foo, > 5'
            ),
            'conjunctive constraints with various dev constraints/4' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-foo'), 'exclude' => true)),
                '!= dev-foo, != dev-foo'
            ),
            'conjunctive constraints with various dev constraints/5' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-foo', 'dev-bar'), 'exclude' => true)),
                '!= dev-foo, != dev-bar'
            ),
            'conjunctive constraints with various dev constraints/6' => array(
                array('numeric' => array(), 'branches' => array('names' => array('dev-bar'), 'exclude' => false)),
                '!= dev-foo, == dev-bar'
            ),
            'conjunctive constraints with various dev constraints/7' => array(
                self::INTERVAL_NONE,
                'dev-foo, > 5'
            ),
            'complex conjunctive which can not match anything' => array(
                self::INTERVAL_NONE,
                '!= 1.0, != 1.0, != dev-foo, 1.0.5.*, == dev-bla'
            ),
            'conjunctive with more than one dev negation' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-master', 'dev-foo'), 'exclude' => true)),
                '!= dev-master, != dev-foo'
            ),
            'disjunctive constraints with various dev constraints' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-foo'), 'exclude' => true)),
                '!= dev-foo, != dev-bar || != dev-foo'
            ),
            'disjunctive constraints with various dev constraints/2' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-foo', 'dev-bar'), 'exclude' => true)),
                '!= dev-foo, != dev-bar || != dev-foo, != dev-bar'
            ),
            'disjunctive constraints with various dev constraints/3' => array(
                self::INTERVAL_ANY,
                new MultiConstraint(array(new Constraint('!=', 'dev-foo'), new Constraint('!=', 'dev-bar')), false),
            ),
            'disjunctive constraints with various dev constraints/4' => array(
                array('numeric' => array(),
                    'branches' => array('names' => array('dev-foo', 'dev-bar'), 'exclude' => false),
                ),
                '== dev-foo || == dev-bar'
            ),
            'disjunctive constraints with various dev constraints/5' => array(
                self::INTERVAL_ANY,
                '== dev-foo || != dev-foo'
            ),
            'disjunctive constraints with various dev constraints/6' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-bar'), 'exclude' => true)),
                '== dev-foo || != dev-bar'
            ),
            'disjunctive constraints with various dev constraints/7' => array(
                array('numeric' => array(
                    array(
                        'start' => '>= 0.0.0.0-dev',
                        'end' => '< '.PHP_INT_MAX.'.0.0.0',
                    ),
                ), 'branches' => array('names' => array('dev-bar'), 'exclude' => true)),
                '== dev-foo || != dev-bar || != dev-bar'
            ),
            'disjunctive constraints with various dev constraints/8' => array(
                self::INTERVAL_ANY,
                '== dev-foo || != dev-bar || != dev-foo'
            ),
            'match-none constraints result in no interval' => array(
                self::INTERVAL_NONE,
                class_exists(MatchNoneConstraint::class) ? new MatchNoneConstraint : new FullConstraint,
            ),
            'match-none constraint inside conjunctive multi results in no interval' => array(
                self::INTERVAL_NONE,
                new MultiConstraint(array(
                    new MultiConstraint(array(
                        new Constraint('==', '1.3.1.0-dev'),
                        new Constraint('==', '1.3.2.0-dev'),
                        new Constraint('==', '1.3.3.0-dev'),
                    ), false),
                    class_exists(MatchNoneConstraint::class) ? new MatchNoneConstraint : new FullConstraint,
                ), true),
            ),
        );
    }
}
