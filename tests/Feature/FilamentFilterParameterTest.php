<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EVERY TABLE FILTER'S QUERY CLOSURE MUST NAME ITS PARAMETER $query.
 *
 * Filament injects the table query BY PARAMETER NAME and then throws the closure's
 * return value away (InteractsWithTableQuery::apply -> evaluate(['query' => ...]);
 * return $query). A closure written as `fn (Builder $q) => $q->where(...)` therefore
 * receives a fresh, model-less builder from the container, modifies that throwaway
 * object, and filters NOTHING. No exception, no warning - the filter checkbox works,
 * the list just never changes. Four filters in this application shipped that way.
 *
 * This test walks every resource in the admin panel, not only the one where it was
 * found: the mistake is invisible in review, costs nothing to make, and the next
 * filter written from muscle memory would repeat it.
 */
class FilamentFilterParameterTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_filter_query_closure_uses_the_injectable_parameter_name(): void
    {
        $this->actingAs($this->adminUser());

        $checked = 0;
        $skipped = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $index = $resource::getPages()['index']->getPage() ?? null;

            if (! is_string($index) || ! class_exists($index)) {
                $skipped[] = $resource . ' (keine Index-Seite)';

                continue;
            }

            try {
                $filters = Livewire::test($index)->instance()->getTable()->getFilters();
            } catch (\Throwable $e) {
                // Recorded, not swallowed - a resource silently dropped from this
                // sweep would make the test read as "all clear" while covering less.
                $skipped[] = $resource . ' (' . $e::class . ')';

                continue;
            }

            foreach ($filters as $filter) {
                foreach ($this->queryClosures($filter) as $property => $closure) {
                    $parameters = (new \ReflectionFunction($closure))->getParameters();

                    if ($parameters === []) {
                        continue;
                    }

                    $checked++;

                    $names = array_map(fn (\ReflectionParameter $p) => $p->getName(), $parameters);

                    $this->assertContains(
                        'query',
                        $names,
                        sprintf(
                            "%s, Filter „%s\" (%s): die Closure hat keinen Parameter \$query, sondern (\$%s). "
                            . 'Filament spritzt die Tabellenabfrage über den Parameternamen ein und verwirft '
                            . 'den Rückgabewert – dieser Filter filtert nichts.',
                            class_basename($resource),
                            $filter->getName(),
                            $property,
                            implode(', $', $names),
                        ),
                    );
                }
            }
        }

        // Guards the sweep itself: if resource discovery ever breaks, the loop runs
        // zero times and the test passes while checking nothing.
        $this->assertGreaterThan(10, $checked, 'Zu wenige Filter geprüft – die Erfassung greift nicht mehr.');

        if ($skipped !== []) {
            fwrite(STDERR, "\nFilterprüfung übersprungen für: " . implode(', ', $skipped) . "\n");
        }
    }

    /**
     * The two closures on a filter that Filament resolves by name.
     *
     * @return array<string, \Closure>
     */
    private function queryClosures(BaseFilter $filter): array
    {
        $found = [];

        foreach (['modifyQueryUsing', 'modifyBaseQueryUsing'] as $property) {
            $reflection = new \ReflectionObject($filter);

            if (! $reflection->hasProperty($property)) {
                continue;
            }

            $p = $reflection->getProperty($property);
            $p->setAccessible(true);
            $value = $p->getValue($filter);

            if ($value instanceof \Closure) {
                $found[$property] = $value;
            }
        }

        return $found;
    }

    private function adminUser(): \App\Models\User
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $user = \App\Models\User::factory()->create();
        $user->assignRole(\Spatie\Permission\Models\Role::findByName('admin'));

        return $user;
    }
}
