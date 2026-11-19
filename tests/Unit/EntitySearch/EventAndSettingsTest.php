<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\EntitySearch;

use InvalidArgumentException;
use Nowo\SearchKitBundle\Event\SearchItemVisibilityEvent;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchSubject;
use Nowo\SearchKitBundle\Search\Group\SearchGroupSettings;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;

final class EventAndSettingsTest extends TestCase
{
    public function testSubjectIsValidated(): void
    {
        self::assertSame('task#7', (new SearchSubject('task', '7'))->key());

        foreach ([['Task', '1'], ["task\n", '1'], ['task', ''], ['task', str_repeat('x', 256)]] as [$type, $id]) {
            try {
                new SearchSubject($type, $id);
                self::fail('Subject ' . $type . '#' . $id . ' must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDenyWinsAndDefaultIsNull(): void
    {
        $one   = new SearchSubject('task', '1');
        $two   = new SearchSubject('task', '2');
        $three = new SearchSubject('task', '3');
        $alien = new SearchSubject('task', '99');
        $event = new SearchItemVisibilityEvent('tasks', [$one, $two, $three, $one], 'alice', 5, 'project:1', 'es');

        self::assertSame([$one, $two, $three], $event->subjects());
        self::assertSame(['tasks', 'alice', 5, 'project:1', 'es'], [$event->group, $event->userIdentifier, $event->tenant, $event->scope, $event->locale]);
        $event->deny($one);
        $event->allow($one);
        $event->allow($two);
        $event->deny($alien);
        $event->allow($alien);

        self::assertSame([false, true, null, null], [$event->decision($one), $event->decision($two), $event->decision($three), $event->decision($alien)]);
        self::assertTrue($event->isDenied($one));
        self::assertFalse($event->isDenied($three));

        $event->stopPropagation();
        self::assertFalse($event->isPropagationStopped(), 'The event is not stoppable.');

        $event->denyAll();
        self::assertTrue($event->isDenied($two));
        self::assertTrue($event->isDenied($three));
    }

    public function testGroupSettings(): void
    {
        $plain = new SearchGroupSettings();
        $rows  = [new SearchGroup('b', 'B', []), new SearchGroup('a', 'A', [])];
        self::assertTrue($plain->isEnabled('x'));
        self::assertNull($plain->priority('x'));
        self::assertFalse($plain->hasPriorities());
        self::assertSame($rows, $plain->sort($rows));
        self::assertFalse((new SearchGroupSettings(['x' => ['enabled' => false]]))->hasPriorities());

        $settings = new SearchGroupSettings([
            'off'   => ['enabled' => false, 'priority' => null],
            'tasks' => ['enabled' => true, 'priority' => 50],
            'low'   => ['priority' => -5],
        ]);
        self::assertFalse($settings->isEnabled('off'));
        self::assertTrue($settings->isEnabled('tasks'));
        self::assertSame(50, $settings->priority('tasks'));
        self::assertTrue($settings->hasPriorities());

        $groups = array_map(static fn (string $key): SearchGroup => new SearchGroup($key, $key, []), ['low', 'pages', 'actions', 'tasks', 'other']);
        $sorted = array_map(static fn (SearchGroup $group): string => $group->key, $settings->sort($groups, 'actions'));
        self::assertSame(['actions', 'tasks', 'pages', 'other', 'low'], $sorted);
    }
}
