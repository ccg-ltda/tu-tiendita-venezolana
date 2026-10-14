<?php

namespace App\Services;

use LogicException;
use Revolution\Google\Sheets\Facades\Sheets;

class BunzyBlogSheet
{
    /** @return array<int, array<int, mixed>> */
    public function rows(): array
    {
        return Sheets::spreadsheet($this->spreadsheetId())
            ->sheet('Blog')
            ->all();
    }

    /** @param array<int, int|string> $row */
    public function append(array $row): void
    {
        Sheets::spreadsheet($this->spreadsheetId())
            ->sheet('Blog')
            ->append([$row]);
    }

    public function appendArticle(
        int $id,
        string $title,
        string $slug,
        string $content,
        string $imageUrl,
        string $publishedAt,
    ): void {
        $this->append($this->articleRow($id, $title, $slug, $content, $imageUrl, $publishedAt, 'published'));
    }

    public function syncArticle(
        int $id,
        string $title,
        string $slug,
        string $content,
        string $imageUrl,
        string $publishedAt,
        string $status,
    ): void {
        $row = $this->articleRow($id, $title, $slug, $content, $imageUrl, $publishedAt, $status);

        foreach ($this->rows() as $index => $existingRow) {
            $existingId = is_array($existingRow) ? ($existingRow[0] ?? null) : null;

            if ((is_int($existingId) || (is_string($existingId) && ctype_digit(trim($existingId)))) && (int) $existingId === $id) {
                Sheets::spreadsheet($this->spreadsheetId())
                    ->sheet('Blog')
                    ->range('A'.($index + 1).':G'.($index + 1))
                    ->update([$row]);

                return;
            }
        }

        $this->append($row);
    }

    /**
     * @return list<array{id:int,title:string,slug:string,content:string,image_url:string,published_at:string}>
     */
    public function publishedArticles(): array
    {
        $articles = [];

        foreach (array_slice($this->rows(), 1) as $row) {
            $article = $this->articleFromRow($row);

            if ($article !== null && $article['status'] === 'published') {
                unset($article['status']);
                $articles[] = $article;
            }
        }

        usort($articles, static function (array $first, array $second): int {
            $dateComparison = strcmp($second['published_at'], $first['published_at']);

            return $dateComparison !== 0 ? $dateComparison : $second['id'] <=> $first['id'];
        });

        return $articles;
    }

    /** @return array{id:int,title:string,slug:string,content:string,image_url:string,published_at:string}|null */
    public function publishedArticleBySlug(string $slug): ?array
    {
        $slug = trim($slug);

        foreach ($this->publishedArticles() as $article) {
            if ($article['slug'] === $slug) {
                return $article;
            }
        }

        return null;
    }

    /** @return array{id:int,title:string,slug:string,content:string,image_url:string,published_at:string,status:string}|null */
    private function articleFromRow(mixed $row): ?array
    {
        if (! is_array($row) || $this->isEmptyRow($row)) {
            return null;
        }

        $values = array_values($row);
        $id = $values[0] ?? null;

        if (! (is_int($id) || (is_string($id) && ctype_digit(trim($id))))) {
            return null;
        }

        $title = $this->cell($values, 1);
        $slug = $this->cell($values, 2);

        if ($title === '' || $slug === '') {
            return null;
        }

        return [
            'id' => (int) $id,
            'title' => $title,
            'slug' => $slug,
            'content' => $this->cell($values, 3),
            'image_url' => $this->cell($values, 4),
            'published_at' => $this->cell($values, 5),
            'status' => $this->cell($values, 6),
        ];
    }

    /** @param array<int, mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, mixed> $values */
    private function cell(array $values, int $index): string
    {
        $value = $values[$index] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return array{0:int,1:string,2:string,3:string,4:string,5:string,6:string} */
    private function articleRow(
        int $id,
        string $title,
        string $slug,
        string $content,
        string $imageUrl,
        string $publishedAt,
        string $status,
    ): array {
        return [
            $id,
            $this->safeSheetText($title),
            $this->safeSheetText($slug),
            $this->safeSheetText($content),
            $this->safeSheetText($imageUrl),
            $publishedAt,
            $this->safeSheetText($status),
        ];
    }

    private function safeSheetText(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function spreadsheetId(): string
    {
        $spreadsheetId = config('google.bunzy_sheet_id');

        if (! is_string($spreadsheetId) || trim($spreadsheetId) === '') {
            throw new LogicException('Bunzy spreadsheet is not configured.');
        }

        return $spreadsheetId;
    }
}
