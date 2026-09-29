<?php

namespace App\Contracts;

interface GoogleSheetsValuesClient
{
    /** @return list<list<mixed>> */
    public function getValues(string $range): array;

    /** @param list<string> $ranges @return list<list<list<mixed>>> */
    public function batchGetValues(array $ranges): array;

    /** @param list<list<mixed>> $values */
    public function updateValues(string $range, array $values): array;

    /** @param list<list<mixed>> $values */
    public function appendValues(string $range, array $values): array;

    /** @param list<array{range:string,values:list<list<mixed>>}> $data */
    public function batchUpdateValues(array $data): array;
}
