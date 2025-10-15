<?php

namespace AiModels;
use Gemini\Enums\ModelVariation;
use Gemini\GeminiHelper;
use Gemini;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Schema;
use Gemini\Enums\DataType;
use Gemini\Enums\ResponseMimeType;


use AiModels\AiModel;

class GeminiModel implements AiModel
{

    public string $name = "GeminiModel";
    private string $key;

    public function __construct()
    {
        $this->key = json_decode(file_get_contents(__DIR__."/keys_private.json"), true)[0]["api_key"];
    }

    public function getOutput(string $input): array
    {

        $prompt = '## ROLLE ##
Du bist ein hochpräziser Assistent für die Extraktion von Entitäten, spezialisiert auf die Erkennung von personenbezogenen Daten (Personally Identifiable Information, PII) in deutschen Texten.

## AUFGABE ##
Deine Aufgabe ist es, den unten stehenden Text sorgfältig zu analysieren und alle Vorkommen von personenbezogenen Daten zu identifizieren und zu extrahieren.

Personenbezogene Daten umfassen unter anderem:
- Vollständige Namen (Vor- und Nachnamen)
- Telefonnummern
- E-Mail-Adressen
- Postanschriften (Straßen, Hausnummern, Postleitzahlen, Städte)
- Geburtsdaten
- Kontonummern (IBAN)
- Persönliche Identifikationsnummern
- Spitznamen
- Etwaige Ausweisnummern

## AUSGABEFORMAT ##
Formatiere deine Ausgabe als einen einzelnen, validen JSON-String in einem Code-Block. Die Ausgabe muss ein Array von Objekten sein. Jedes Objekt repräsentiert ein gefundenes personenbezogenes Datum und muss exakt die folgenden drei Schlüssel enthalten:
1. "id": Eine fortlaufende ganze Zahl, beginnend mit 1.
2. "word": Das extrahierte personenbezogene Datum als String.
3. "type": Der Typ des personenbezogenen Datums.
4. "length": Die genaue Zeichenlänge des extrahierten Datums, formatiert als String.

## REGELN ##
- Deine Antwort darf ausschließlich den JSON-String enthalten. Füge keine Erklärungen oder einleitenden Sätze hinzu.
- Wenn im Text keine personenbezogenen Daten gefunden werden, gib ein leeres Array `[]` zurück.
- Fasse zusammengehörige Daten wie "Max Mustermann" als einzelne Einträge für "Max" und "Mustermann" auf. Eine komplette Adresse wie "Musterstraße 123" sollte jedoch als ein einziger Eintrag extrahiert werden.

## BEISPIEL ##
Text: "Kontaktieren Sie bitte Eva Schmidt unter eva.schmidt@beispiel.com oder rufen Sie an: 0176 12345678."
Erwartete Ausgabe:
json:
[
  {
    "id": 1,
    "word": "Eva",
    "type": "Vorname",
    "length": "3"
  },
  {
    "id": 2,
    "word": "Schmidt",
    "type": "Nachname",
    "length": "7"
  },
  {
    "id": 3,
    "word": "eva.schmidt@beispiel.com",
    "type": "E-Mail Adresse",
    "length": "24"
  },
  {
    "id": 4,
    "word": "0176 12345678",
    "type": "Telefonnummer",
    "length": "13"
  }
]

## TEXT ZUR ANALYSE ##
';
        $prompt .= $input;

        $client = Gemini::client($this->key);
        $result = $client->generativeModel(model: 'gemini-2.0-flash')->withGenerationConfig(
            generationConfig: new GenerationConfig(
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
                responseSchema: new Schema(
                    type: DataType::ARRAY,
                    items: new Schema(
                        type: DataType::OBJECT,
                        properties: [
                            'id' => new Schema(type: DataType::INTEGER),
                            'word' => new Schema(type: DataType::STRING),
                            'type' => new Schema(type: DataType::STRING),
                            'length' => new Schema(type: DataType::INTEGER),
                        ],
                        required: ['word', 'length'],
                    )
                )
            )
        )->generateContent($prompt)->json();

        return array_map(function($item){
            return $item->word;
        }, $result);
    }

    public string $path {
        get {
            return $this->path;
        }
    }
}