<?php
declare(strict_types=1);

namespace ReligionPlatform;

/** Parses roster files without creating identities or making name-based matches. */
final class RosterImport
{
    private const MAX_BYTES = 5242880;
    private const MAX_ROWS = 100;

    public static function parse(array $file, string $pasted, string $mode): array
    {
        if (!in_array($mode,['last_first','first_last','one'],true)) $mode='last_first';
        $rows=[];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
                throw new \InvalidArgumentException('Die Datei konnte nicht sicher empfangen werden.');
            }
            $size=(int)($file['size'] ?? 0);
            if($size<1 || $size>self::MAX_BYTES) throw new \InvalidArgumentException('Die Datei ist leer oder größer als 5 MB.');
            $extension=strtolower(pathinfo((string)($file['name'] ?? ''),PATHINFO_EXTENSION));
            $path=(string)$file['tmp_name'];
            if(in_array($extension,['csv','txt'],true)) $rows=self::delimited((string)file_get_contents($path));
            elseif(in_array($extension,['xlsx','xlsm'],true)) $rows=self::xlsx($path);
            else throw new \InvalidArgumentException('Bitte eine XLSX-, XLSM-, CSV- oder TXT-Datei auswählen.');
        } elseif(trim($pasted)!=='') {
            $rows=self::delimited($pasted);
        } else throw new \InvalidArgumentException('Bitte eine Datei auswählen oder Namen aus der Zwischenablage einfügen.');
        return self::normalise($rows,$mode);
    }

    /** Testable entry point; production uploads still pass through parse(). */
    public static function parseText(string $text,string $mode='last_first'): array
    {
        if (!in_array($mode,['last_first','first_last','one'],true)) $mode='last_first';
        return self::normalise(self::delimited($text),$mode);
    }

    private static function delimited(string $text): array
    {
        $text=preg_replace('/^\xEF\xBB\xBF/','',$text) ?? $text;
        $delimiter=substr_count($text,"\t")>max(substr_count($text,';'),substr_count($text,','))?"\t":(substr_count($text,';')>=substr_count($text,',')?';':',');
        $stream=fopen('php://temp','r+');
        if(!$stream) throw new \RuntimeException('Die Liste konnte nicht gelesen werden.');
        fwrite($stream,$text);rewind($stream);$rows=[];
        while(($row=fgetcsv($stream,0,$delimiter))!==false) $rows[]=$row;
        fclose($stream);return $rows;
    }

    private static function xlsx(string $path): array
    {
        if(!class_exists(\ZipArchive::class) || !function_exists('simplexml_load_string')) throw new \RuntimeException('Der Server benötigt PHP-ZIP und PHP-XML für den Excel-Import. CSV und Einfügen funktionieren bereits.');
        $zip=new \ZipArchive();
        if($zip->open($path)!==true) throw new \InvalidArgumentException('Die Excel-Datei konnte nicht geöffnet werden.');
        try {
            $shared=[];$sharedXml=$zip->getFromName('xl/sharedStrings.xml');
            if(is_string($sharedXml)) {
                $xml=simplexml_load_string($sharedXml);
                if($xml) foreach($xml->si as $item) {
                    $parts=[];
                    if(isset($item->t)) $parts[]=(string)$item->t;
                    foreach($item->r as $run) $parts[]=(string)$run->t;
                    $shared[]=implode('',$parts);
                }
            }
            $sheetXml=$zip->getFromName('xl/worksheets/sheet1.xml');
            if(!is_string($sheetXml)) throw new \InvalidArgumentException('Die erste Excel-Tabelle fehlt.');
            $sheet=simplexml_load_string($sheetXml);
            if(!$sheet) throw new \InvalidArgumentException('Die Excel-Tabelle ist beschädigt.');
            $rows=[];
            foreach($sheet->sheetData->row as $row) {
                $cells=[];
                foreach($row->c as $cell) {
                    $reference=(string)$cell['r'];preg_match('/^[A-Z]+/',$reference,$column);
                    $index=self::columnIndex($column[0] ?? 'A');
                    while(count($cells)<$index) $cells[]='';
                    $type=(string)$cell['t'];
                    $value=$type==='inlineStr'?(string)$cell->is->t:(string)$cell->v;
                    if($type==='s') $value=$shared[(int)$value] ?? '';
                    $cells[$index]=$value;
                }
                $rows[]=$cells;
            }
            return $rows;
        } finally {$zip->close();}
    }

    private static function columnIndex(string $letters): int
    {
        $index=0;foreach(str_split($letters) as $letter)$index=$index*26+(ord($letter)-64);return max(0,$index-1);
    }

    private static function normalise(array $rows,string $mode): array
    {
        $result=[];$autoNumber=1;
        foreach($rows as $raw) {
            if(!is_array($raw)) continue;
            $cells=array_values(array_filter(array_map(static fn($value)=>Security::clean((string)$value,160),$raw),static fn($value)=>$value!==''));
            if(!$cells) continue;
            $header=strtolower(implode(' ',$cells));
            if(!$result && preg_match('/(listen)?nummer|vorname|nachname|schüler|schueler|\bname\b/u',$header)) continue;
            $number=0;
            if(count($cells)>1 && preg_match('/^[1-9][0-9]{0,2}$/D',$cells[0])) $number=(int)array_shift($cells);
            if($number===0) $number=$autoNumber;
            $autoNumber=max($autoNumber,$number+1);
            if($mode==='one' || count($cells)===1) $name=implode(' ',$cells);
            elseif($mode==='last_first') $name=trim(($cells[1] ?? '').' '.$cells[0]);
            else $name=trim($cells[0].' '.($cells[1] ?? ''));
            $name=Security::clean($name,120);
            if($name==='') continue;
            $result[]=['number'=>$number,'name'=>$name];
            if(count($result)>self::MAX_ROWS) throw new \InvalidArgumentException('Pro Import sind höchstens 100 Personen erlaubt.');
        }
        if(!$result) throw new \InvalidArgumentException('In der Liste wurden keine Namen gefunden.');
        return $result;
    }
}
