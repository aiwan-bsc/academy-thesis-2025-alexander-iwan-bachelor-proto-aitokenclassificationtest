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

    use Codewithkyrian\Transformers\Transformers;
    use toolkits\AiToolkit;
    use function codewithkyrian\transformers\pipelines\pipeline;

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


    // Fehlerbehebung 502er bei lokalen Modellen, mehrere Libraries binden libomp ein
    // OMP: Error #15: Initializing libomp.dylib, but found libomp.dylib already initialized.
    //
    // OMP: Hint This means that multiple copies of the OpenMP runtime have been linked into the program. That is dangerous, since it can degrade performance or cause incorrect results. The best thing to do is to ensure that only a single OpenMP runtime is linked into the process, e.g. by avoiding static linking of the OpenMP runtime in any library. As an unsafe, unsupported, undocumented workaround you can set the environment variable KMP_DUPLICATE_LIB_OK=TRUE to allow the program to continue to execute, but that may cause crashes or silently produce incorrect results. For more information, please see http://openmp.llvm.org/
    putenv('KMP_DUPLICATE_LIB_OK=TRUE');

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

        //Schritt 4: Informationen Zensieren
        try {
            $pdfToolkit->createCensoredPdfWithBlacklistWithBoxes($aiResponse);
            //$pdfToolkit->createCensoredPdfWithBlacklistWithHTML($aiResponse);
        } catch (\Mpdf\MpdfException
                |\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException
                |\setasign\Fpdi\PdfParser\Type\PdfTypeException
                |\setasign\Fpdi\PdfParser\PdfParserException
                |\Smalot\PdfParser\Exception\MissingCatalogException|Exception $e) {
            echo $e->getMessage();
        }
    }