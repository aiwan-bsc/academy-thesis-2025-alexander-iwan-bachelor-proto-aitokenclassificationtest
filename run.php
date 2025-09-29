<?php
    declare(strict_types=1);
    require 'vendor/autoload.php';

    use AiModels\GeminiModel;
    use AiModels\piiranha;
use AiModels\piiSensitiveNerGerman;
use toolkits\PDFToolkit;

    require 'AiModels/AiModel.php';
    require 'AiModels/piiSensitiveNerGerman.php';
    require 'AiModels/piiranha.php';
    require 'AiModels/GeminiModel.php';

    require 'toolkits/PDFToolkit.php';

    echo '
    <html lang="de">
        <h1>KI-Test</h1>
        <form action="run.php" method="post" enctype="multipart/form-data">
            <table>
                <tr>
                    <td>
                        <label for="eingabe">Eingabe</label>
                    </td>
                    <td>
                        <textarea name="eingabe" id="eingabe"></textarea><br>
                        <input type="file" name="pdfFile" id="pdfFile"><br>
                        <input type="radio" id="parsing_parse" name="parsing_type" value="parse" checked><label for="parsing_parse">Text-Parsing</label><br>
                        <input type="radio" id="parsing_ocr" name="parsing_type" value="ocr"><label for="parsing_ocr">OCR</label><br>
                        <input type="radio" id="parsing_mixed" name="parsing_type" value="mixed"><label for="parsing_mixed">Mixed</label><br>
                        <button type="submit">Abschicken</button>
                    </td>
                </tr>
            </table>
        </form>';


    //Auf den Models-Ordner im Projekt verweisen, damit das lokale Modell gefunden werden kann
    /*Transformers::setup()
            ->setCacheDir(__DIR__ .'/Models/')
            ->apply();*/

    /*if(isset($_POST['eingabe'])) {
        $pdfToolkit = new PDFToolkit(new piiranha());
        echo '<pre>'.var_dump($pdfToolkit->inputIntoAI($_POST['eingabe'])).'</pre>';
        die();
    }*/

    if(isset($_FILES['pdfFile']['name']) && $_FILES['pdfFile']['name'] !== '') {

        //Schritt 1: Die Datei in den richtigen Ordner bewegen
        try {
            $pdfToolkit = new PdfToolkit(new GeminiModel(), $_FILES['pdfFile']);
        } catch (Exception $e) {
            echo $e->getMessage();
            return;
        }

        // Schritt 2: Text aus dem PDF extrahieren
        try {
            var_dump($_POST);
            $pdfText = $pdfToolkit->extractTextFromPdf($_POST['parsing_type']);
        } catch (Exception $e) {
            echo $e->getMessage();
            return;
        }
        echo '<pre>'.$pdfText.'</pre>';
        //Schritt 3: Text mit KI prüfen lassen
        try{
            $aiResponse = $pdfToolkit->inputIntoAI();
        } catch (Exception $e) {
            echo $e->getMessage();
            return;
        }

        echo "KI-Output:<br>";
        var_dump($aiResponse);

        //Schritt 4: Text anpassen
        //echo $helper->group_entities($entities);
       // echo '<pre>'.$pdfToolkit->getCensoredTextFromWordList($aiResponse).'</pre>';
        die();
        try {
            $pdfToolkit->createCensoredPdfWithBlacklistWithHTML($aiResponse);
        } catch (\Mpdf\MpdfException
                |\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException
                |\setasign\Fpdi\PdfParser\Type\PdfTypeException
                |\setasign\Fpdi\PdfParser\PdfParserException|\Smalot\PdfParser\Exception\MissingCatalogException $e) {
            echo $e->getMessage();
        }
    }