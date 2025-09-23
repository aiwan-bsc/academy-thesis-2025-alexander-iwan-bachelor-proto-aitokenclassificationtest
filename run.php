<?php
    declare(strict_types=1);
    require 'vendor/autoload.php';
    use Codewithkyrian\Transformers\Transformers;
    use Smalot\PdfParser\Parser;

    require 'AiModel.php';
    require 'piiSensitiveNerGerman.php';
    require 'piiranha.php';

    require 'PDFToolkit.php';

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
                        <textarea name="eingabe" id="eingabe"> </textarea><br>
                        <input type="file" name="pdfFile" id="pdfFile"><br>
                        <button type="submit">Abschicken</button>
                    </td>
                </tr>
            </table>
        </form>';


    //Auf den Models-Ordner im Projekt verweisen, damit das lokale Modell gefunden werden kann
    /*Transformers::setup()
            ->setCacheDir(__DIR__ .'/Models/')
            ->apply();*/

    if(isset($_POST['eingabe'])) {
        $pdfToolkit = new PDFToolkit(new piiranha());
        echo '<pre>'.var_dump($pdfToolkit->inputIntoAI($_POST['eingabe'])).'</pre>';
    }

    if(isset($_FILES['pdfFile']['name']) && $_FILES['pdfFile']['name'] !== '') {
        var_dump($_FILES['pdfFile']['name']);
        //Schritt 1: Die Datei in den richtigen Ordner bewegen
        try {
            $pdfToolkit = new PdfToolkit(new piiSensitiveNerGerman(), $_FILES['pdfFile']);
        } catch (Exception $e) {
            echo $e->getMessage();
            return;
        }

        // Schritt 2: Text aus dem PDF extrahieren
        try {
            $pdfText = $pdfToolkit->extractTextFromPdf();
        } catch (Exception $e) {
            echo $e->getMessage();
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
        echo var_dump($aiResponse);

        //Schritt 4: Text anpassen
        //echo $helper->group_entities($entities);
       // echo '<pre>'.$pdfToolkit->getCensoredTextFromWordList($aiResponse).'</pre>';
        try {
            $pdfToolkit->createCensoredPdfWithBlacklist($aiResponse);
        } catch (\Mpdf\MpdfException
                |\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException
                |\setasign\Fpdi\PdfParser\Type\PdfTypeException
                |\setasign\Fpdi\PdfParser\PdfParserException $e) {
            echo $e->getMessage();
        }
    }