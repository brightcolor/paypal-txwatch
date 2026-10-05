<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The GitHub workflows of this repository. Each one names the rights of its
 * token in a permissions block at the top level, and each action is pinned to
 * the full commit SHA of its release, with the version as a comment behind it.
 * Local actions (./…) and container images (docker://…) are used as written.
 */
class WorkflowPinsTest extends TestCase
{
    private const WORKFLOWS = '.github/workflows';

    public function test_every_workflow_limits_its_token_and_pins_its_actions(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob("{$root}/" . self::WORKFLOWS . '/*.yml') ?: [],
            glob("{$root}/" . self::WORKFLOWS . '/*.yaml') ?: [],
        );

        $this->assertNotSame([], $files, 'Unter ' . self::WORKFLOWS . ' liegt kein Workflow (.yml oder .yaml).');

        $problems = [];
        foreach ($files as $file) {
            foreach (self::problems((string) file_get_contents($file)) as $problem) {
                $problems[] = basename($file) . ", {$problem}";
            }
        }

        $this->assertSame([], $problems, "Workflows mit offenen Punkten:\n" . implode("\n", $problems));
    }

    public function test_the_check_reports_movable_references_and_a_missing_permissions_block(): void
    {
        $workflow = <<<'YAML'
            name: Beispiel
            on: push
            jobs:
              build:
                runs-on: ubuntu-latest
                permissions:
                  contents: read
                steps:
                  - uses: actions/checkout@v5
                  - uses: actions/cache@main
                  - name: Kurze SHA
                    uses: docker/login-action@c94ce9f
                  - uses: actions/setup-node@0057852bfaa89a56745cba8c7296529d2fc39830
                  - uses: "actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09" # v5.1.0
                  - uses: ./.github/actions/lokal
                  - uses: docker://alpine:3.20
            YAML;

        $this->assertSame([
            'oberste Ebene: Es fehlt ein permissions-Block. Die Rechte des Tokens dort festlegen, etwa „permissions:“ mit „contents: read“.',
            'Zeile 9: „actions/checkout@v5“ ist auf keinen Commit festgelegt. Die volle SHA des Releases eintragen und die Version als Kommentar dahinter.',
            'Zeile 10: „actions/cache@main“ ist auf keinen Commit festgelegt. Die volle SHA des Releases eintragen und die Version als Kommentar dahinter.',
            'Zeile 12: „docker/login-action@c94ce9f“ ist auf keinen Commit festgelegt. Die volle SHA des Releases eintragen und die Version als Kommentar dahinter.',
            'Zeile 13: Zu „actions/setup-node@0057852bfaa89a56745cba8c7296529d2fc39830“ fehlt die Version als Kommentar, etwa „# v4.3.0“.',
        ], self::problems($workflow));
    }

    public function test_a_pinned_workflow_with_permissions_passes(): void
    {
        $workflow = <<<'YAML'
            on: pull_request
            permissions:
              contents: read
            jobs:
              test:
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09 # v5.1.0
                  - name: Cache
                    uses: actions/cache@0057852bfaa89a56745cba8c7296529d2fc39830   # v4.3.0
              reuse:
                uses: octo-org/ci/.github/workflows/build.yml@8d2750c68a42422c14e847fe6c8ac0403b4cbd6f # v3.12.0
            YAML;

        $this->assertSame([], self::problems($workflow));
    }

    /** @return list<string> */
    public static function problems(string $workflow): array
    {
        $problems = [];

        if (preg_match('/^permissions:/m', $workflow) !== 1) {
            $problems[] = 'oberste Ebene: Es fehlt ein permissions-Block. Die Rechte des Tokens dort festlegen, etwa „permissions:“ mit „contents: read“.';
        }

        foreach (preg_split('/\R/', $workflow) as $index => $line) {
            if (preg_match('/^\s*(?:-\s+)?uses:\s*["\']?([^\s"\'#]+)["\']?\s*(#.*)?$/', $line, $match) !== 1) {
                continue;
            }

            $reference = $match[1];
            if (str_starts_with($reference, './') || str_starts_with($reference, 'docker://')) {
                continue;
            }

            $number = $index + 1;
            if (preg_match('/^[^@\s]+@[0-9a-f]{40}$/', $reference) !== 1) {
                $problems[] = "Zeile {$number}: „{$reference}“ ist auf keinen Commit festgelegt. Die volle SHA des Releases eintragen und die Version als Kommentar dahinter.";

                continue;
            }

            if (trim(ltrim($match[2] ?? '', '#')) === '') {
                $problems[] = "Zeile {$number}: Zu „{$reference}“ fehlt die Version als Kommentar, etwa „# v4.3.0“.";
            }
        }

        return $problems;
    }
}
