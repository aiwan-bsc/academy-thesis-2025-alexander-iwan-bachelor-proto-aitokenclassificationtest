<?php

namespace toolkits;

use AiModels\AiModel;
use Exception;
use FilesystemIterator;
use Mpdf;
use Mpdf\MpdfException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Smalot\PdfParser\Exception\MissingCatalogException;
use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\Command;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Imagine\Imagick;




class PDFToolkit
{
    private const string SAVE_PATH = __DIR__ . "/../uploads/";
    private const string TEMP_PATH = __DIR__ . "/../temp/";
    private const string OUTPUT_PATH = __DIR__ . "/../uploads/output/";
    private const string STANDARD_PARSING_TYPE = "parse";
    private array $pdf = array(
        'filePath' => '',
        'text' => ''
    );
    private AiModel $aiModel;

    /**
     * @throws Exception
     */
    public function __construct(AiModel $givenModel, ?array $pdfFile = null)
    {
        if ($pdfFile !== null) {
            $this->loadPDF($pdfFile);
        }
        $this->aiModel = $givenModel;
    }

    public function setAiModel(AiModel $aiModel): void
    {
        $this->aiModel = $aiModel;
    }

    /**
     * @throws Exception
     */
    public function loadPDF(array $pdfFile): bool
    {
        $this->pdf['filePath'] = $this->savePdfToFolder($pdfFile);
        $this->pdf['text'] = '';
        return true;
    }

    /**
     * @throws Exception
     */
    private function savePdfToFolder(array $pdfFile): string
    {
        $targetPath = self::SAVE_PATH.basename($pdfFile["name"]);
        if (move_uploaded_file($pdfFile["tmp_name"], $targetPath)) {
            return $targetPath;
        } else {
            throw new Exception("Fehler beim Speichern der PDF");
        }
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public function extractTextFromPdf(?string $type = self::STANDARD_PARSING_TYPE): string
    {
        $text = "";
        var_dump($type);
        if($type === 'parse'){
            $parser = new Parser();
            $pdf = $parser->parseFile($this->pdf['filePath']);
            $text = $pdf->getText();
        }else if($type === 'ocr'){
            $pdf = new \Spatie\PdfToImage\Pdf($this->pdf['filePath']);
            for($i = 1; $i <= $pdf->getNumberOfPages(); $i++){
                $pdf->setPage($i);
                $pdf->saveImage(self::TEMP_PATH."temp.jpg");

                $langCommand = new Command();
                $langCommand->options[] = "-l deu"; //Deutsche Sprache prüfen, um Umlaute und 'ß' abzufangen
                $text .= new TesseractOCR(self::TEMP_PATH."temp.jpg", $langCommand)->run()." ";
            }
        }else if($type === 'mixed'){
            $parser = new Parser();
            $pdf = $parser->parseFile($this->pdf['filePath']);
            $text = $pdf->getText();

            $command = sprintf("pdfimages -png %s %s", $this->pdf['filePath'], self::TEMP_PATH . "imageParseOutput");
            exec($command);

            $pageStartIndex = 1;
            while(file_exists(self::TEMP_PATH . "imageParseOutput-".$pageStartIndex.".html")){
                $langCommand = new Command();
                $langCommand->options[] = "-l deu"; //Deutsche Sprache prüfen, um Umlaute und 'ß' abzufangen
                $text .= new TesseractOCR(self::TEMP_PATH . "imageParseOutput-".$pageStartIndex, $langCommand)->run()." ";
                $pageStartIndex++;
            };
        }

        if (empty(trim($text))) {
            throw new Exception("Es konnte kein Text in der PDF gefunden werden");
        } else {
            $this->pdf['text'] = $text;
            return $text;
        }
    }

    /**
     * @throws Exception
     */
    public function inputIntoAI(?string $text = null): array
    {
        if (!is_null($text)) {
            return $this->aiModel->getOutput($text);
        } else {
            if ($this->pdf['text'] !== '') {
                return $this->aiModel->getOutput($this->pdf['text']);
            }
            return $this->aiModel->getOutput($this->extractTextFromPdf());
        }
    }

    public function getCensoredTextFromWordList(array $blacklist, ?string $textToCensor = null): string
    {
        if (!is_null($textToCensor)) {
            return str_ireplace($blacklist, '(zensiert)', $textToCensor);
        }
        return str_ireplace($blacklist, '(zensiert)', $this->pdf['text']);
    }


    /**
     * @throws MpdfException
     */
    public function createCensoredPdfWithBlacklistWithHTML(array $blacklist): void
    {
        // 1. Mit "pdftohtml [input] [output] -c -s" Befehl die PDF-Datei zu HTML konvertieren
        // ---- ENTWEDER ----
        // 2. HTML in MPDF laden
        // 3. MPDF Overwrite nutzen

        // ---- ODER ----
        // b. HTML direkt bearbeiten (ohne Bilder etc kaputt zu machen)
        // c. MPDF mit neuem HTML füttern

        // 1.
        $command = sprintf("pdftohtml -c %s %s", $this->pdf['filePath'], self::TEMP_PATH . "output.html");
        print($command);
        exec($command);

        // 2.
        $newPDF = new Mpdf\Mpdf();
        $newPDF->percentSubset = 0;

        $pageStartIndex = 1;
        do{
            //TODO: Weiße Seite entfernen
            $html = str_replace("page-break-after: always;",
                "",
                file_get_contents(self::TEMP_PATH . "output-".$pageStartIndex.".html"));
            $newPDF->WriteHTML($html);
            $pageStartIndex++;
        }while(file_exists(self::TEMP_PATH . "output-".$pageStartIndex.".html"));

        //$newPDF->WriteHTML(file_get_contents(self::TEMP_PATH . "output-html.html"));
        $newPDF->Output(self::TEMP_PATH . "temp.pdf", "F");

        // 3.
        $replacementArray = array_map(function ($blacklistedString) {
            return str_repeat("█", strlen($blacklistedString));
        }, $blacklist);

        $mpdf = new \Mpdf\Mpdf();
        $mpdf->OverWrite(self::TEMP_PATH . "temp.pdf",
            $blacklist,
            $replacementArray,
            "F",
            self::OUTPUT_PATH . basename($this->pdf['filePath'], '.pdf') . "_zensiert.pdf");

        //self::deleteTemp();
    }

    /**
     * @throws MissingCatalogException
     * @throws Exception
     */
    public function createCensoredPdfWithBlacklistWithBoxes(array $blacklist): void
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($this->pdf['filePath']);

        $pages = $pdf->getPages();
        $page = $pages[0];

        $dataTm = $page->getDataTm();

        echo("<pre>");
        //var_dump($dataTm);
        echo("</pre>");

    }

    private static function deleteTemp() : void
    {
        $dir = self::TEMP_PATH;
        if(file_exists($dir)){
            $di = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
            $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ( $ri as $file ) {
                $file->isDir() ?  rmdir($file) : unlink($file);
            }
        }

    }
}