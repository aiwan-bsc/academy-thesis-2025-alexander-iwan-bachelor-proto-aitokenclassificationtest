<?php

use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use Smalot\PdfParser\Parser;
use Codewithkyrian\Transformers\Transformers;
use function codewithkyrian\transformers\pipelines\pipeline;

class PDFToolkit
{
    private const string SAVE_PATH = __DIR__ ."/uploads/";
    private const string TEMP_PATH = __DIR__ ."/temp/";
    private const string OUTPUT_PATH = __DIR__ ."/uploads/output/";
    private array $pdf = array(
        'filePath'  => '',
        'text'      => ''
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
    public function loadPDF(array $pdfFile) :bool
    {
        $this->pdf['filePath'] = $this->savePdfToFolder($pdfFile);
        $this->pdf['text'] = '';
        return true;
    }

    /**
     * @throws Exception
     */
    private function savePdfToFolder(array $pdfFile) :string
    {
        $targetPath = self::SAVE_PATH . basename($pdfFile["name"]);
        if(move_uploaded_file($pdfFile["tmp_name"], $targetPath)){
            return $targetPath;
        }else{
            throw new Exception("Fehler beim Speichern der PDF");
        }
    }

    /**
     * @throws Exception
     */
    public function extractTextFromPdf() :string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($this->pdf['filePath']);
        $text = $pdf->getText();

        if (empty(trim($text))) {
            throw new Exception("Es konnte kein Text in der PDF gefunden werden");
        }else{
            $this->pdf['text'] = $text;
            return $text;
        }
    }

    /**
     * @throws Exception
     */
    public function inputIntoAI(?string $text = null) :array
    {
        if(!is_null($text)){
            return $this->aiModel->getOutput($text);
        }else{
            if($this->pdf['text'] !== ''){
                return $this->aiModel->getOutput($this->pdf['text']);
            }
            return $this->aiModel->getOutput($this->extractTextFromPdf());
        }
    }

    public function getCensoredTextFromWordList(array $blacklist, ?string $textToCensor = null) :string
    {
        if(!is_null($textToCensor)){
            return str_ireplace($blacklist, '(zensiert)', $textToCensor);
        }
        return str_ireplace($blacklist, '(zensiert)', $this->pdf['text']);
    }


    /**
     * @throws MpdfException
     */
    public function createCensoredPdfWithBlacklist(array $blacklist) :void
    {
        // 1. Mit "pdftohtml [input] [output] -c -s" Befehl die PDF-Datei zu HTML konvertieren
        // ---- ENTWEDER ----
        // 2. HTML in MPDF laden
        // 3. MPDF Overwrite nutzen

        // ---- ODER ----
        // b. HTML direkt bearbeiten (ohne Bilder etc kaputt zu machen)
        // c. MPDF mit neuem HTML füttern

        // 1.
        $command = sprintf("pdftohtml -c -s %s %s", $this->pdf['filePath'], self::TEMP_PATH."output.html");
        print($command);
        exec($command);

        // 2.
        $newPDF = new Mpdf\Mpdf();
        $newPDF->percentSubset = 0;

        $newPDF->WriteHTML(file_get_contents(self::TEMP_PATH."output-html.html"));
        $newPDF->Output(self::TEMP_PATH."temp.pdf", "F");

        // 3.
        $replacementArray = array_map(function ($blacklistedString) {
            return str_repeat("█", strlen($blacklistedString));
        }, $blacklist);

        $mpdf = new \Mpdf\Mpdf();
        $mpdf->OverWrite(self::TEMP_PATH."temp.pdf",
            $blacklist,
            $replacementArray,
            "F",
            self::OUTPUT_PATH.basename($this->pdf['filePath'], '.pdf')."_zensiert.pdf");
    }
}