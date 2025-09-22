<?php

use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use Smalot\PdfParser\Parser;
use Codewithkyrian\Transformers\Transformers;
use function codewithkyrian\transformers\pipelines\pipeline;

class PDFToolkit
{
    private const string SAVE_PATH = __DIR__ ."/uploads/";
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
        $searchTerms = array_values($blacklist);

        $replacementTerms = [];
        foreach ($searchTerms as $term) {
            $replacementTerms[] = str_repeat('█', strlen($term));
        }

        $mpdf = new \Mpdf\Mpdf();

        //OverWrite funktioniert nur mit von MPDF erstellen PDFs!
        $mpdf->OverWrite(
            $this->pdf['filePath'],
            $searchTerms,
            $replacementTerms,
            Destination::FILE,
            self::SAVE_PATH . 'zensiert.pdf',

        );
    }
}