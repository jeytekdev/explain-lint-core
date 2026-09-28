<?php

declare(strict_types=1);

namespace ExplainLint\Report;

final class JUnitReporter
{
    public function write(ResultCollector $results, string $path): void
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $suite = $document->createElement('testsuite');
        $suite->setAttribute('name', 'explain-lint');
        $suite->setAttribute('tests', (string) max(count($results->all()), 1));
        $suite->setAttribute('failures', (string) $results->violationCount());
        $document->appendChild($suite);

        if ($results->all() === []) {
            $case = $document->createElement('testcase');
            $case->setAttribute('name', 'explain-lint');
            $case->setAttribute('classname', 'explain-lint');
            $suite->appendChild($case);
        }

        foreach ($results->all() as $outcome) {
            foreach ($outcome->violations as $violation) {
                $case = $document->createElement('testcase');
                $case->setAttribute('name', $outcome->testName);
                $case->setAttribute('classname', 'explain-lint.' . $violation->reasonCode->value);

                $failure = $document->createElement('failure');
                $failure->setAttribute('type', $violation->reasonCode->value);
                $failure->setAttribute('message', $violation->describe());
                // Not part of the JUnit schema, but read back by
                // `explain-lint:check` to only treat Error-severity
                // violations as build-breaking; generic JUnit consumers
                // (CI dashboards) ignore unknown attributes safely.
                $failure->setAttribute('severity', $violation->severity->value);
                $failure->appendChild($document->createTextNode(
                    $violation->normalizedSql . "\n\n"
                    . $violation->recommendation() . "\n\n"
                    . json_encode($violation->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                ));

                $case->appendChild($failure);
                $suite->appendChild($case);
            }
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $document->save($path);
    }
}
