<?php

namespace Tests\Unit\Loans;

use App\Support\Xlsx\XlsxReader;
use App\Support\Xlsx\XlsxWriter;
use PHPUnit\Framework\TestCase;

class XlsxTest extends TestCase
{
    public function testWriterAndReaderRoundTrip(): void
    {
        $bin = (new XlsxWriter)
            ->addSheet('Loans', [
                [['Employee No', XlsxWriter::STYLE_HEADER], ['Amount', XlsxWriter::STYLE_HEADER]],
                ['00123', 150000],
                ['A & B <x>', 2500.5],
            ], ['widths' => [1 => 20, 2 => 14], 'textColumns' => [1], 'freezeHeader' => true, 'validations' => [['sqref' => 'A2:A50', 'list' => 'Yes,No']]])
            ->addSheet('Lists', [['CODE'], ['STAFF']])
            ->toBinary();

        $f = tempnam(sys_get_temp_dir(), 't').'.xlsx';
        file_put_contents($f, $bin);

        $this->assertSame(['Loans', 'Lists'], XlsxReader::sheetNames($f));
        $rows = XlsxReader::read($f, 'Loans');
        $this->assertSame('Employee No', $rows[1][1]);
        $this->assertSame('00123', $rows[2][1]);       // leading zeros kept
        $this->assertSame('150000', $rows[2][2]);
        $this->assertSame('A & B <x>', $rows[3][1]);
        $this->assertSame('2500.5', $rows[3][2]);
        $this->assertSame('STAFF', XlsxReader::read($f, 'Lists')[2][1]);
        unlink($f);
    }

    public function testColumnLetters(): void
    {
        $this->assertSame('A', XlsxWriter::colLetter(1));
        $this->assertSame('Z', XlsxWriter::colLetter(26));
        $this->assertSame('AA', XlsxWriter::colLetter(27));
        $this->assertSame(27, XlsxReader::colIndex('AA10'));
    }
}
