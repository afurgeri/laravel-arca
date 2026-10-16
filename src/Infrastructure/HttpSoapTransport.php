<?php

namespace Modules\Arca\Infrastructure;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Arca\Exceptions\ArcaException;

class HttpSoapTransport implements SoapTransport
{
    public function __construct(
        private readonly int $timeout = 20,
        private readonly int $connectTimeout = 5,
        private readonly ?string $cipherList = 'ECDHE+AESGCM',
    ) {}

    public function call(string $endpoint, string $namespace, string $operation, array $arguments): array
    {
        $body = $this->xmlElement($operation, $arguments, 'tns');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="'
            .$this->escape($namespace).'">'
            .'<soap:Body>'.$body.'</soap:Body></soap:Envelope>';

        try {
            $request = Http::withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => $operation === 'loginCms' ? '""' : '"'.$namespace.$operation.'"',
            ])->connectTimeout($this->connectTimeout)->timeout($this->timeout)
                ->withBody($xml, 'text/xml; charset=utf-8');

            if ($this->cipherList !== null && defined('CURLOPT_SSL_CIPHER_LIST')) {
                $request = $request->withOptions(['curl' => [CURLOPT_SSL_CIPHER_LIST => $this->cipherList]]);
            }

            $response = $request->post($endpoint);
        } catch (ConnectionException $exception) {
            $reason = preg_replace('/[A-Za-z0-9+\/_=-]{64,}/', '[redacted]', $exception->getMessage()) ?? '';
            $reason = substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $reason) ?? '', 0, 180);

            throw new ArcaException("ARCA SOAP operation {$operation} failed before receiving a response: {$reason}");
        } catch (\Throwable $exception) {
            throw new ArcaException("ARCA SOAP operation {$operation} failed before receiving a response (".class_basename($exception).').');
        }

        $document = new DOMDocument;

        if (! $document->loadXML($response->body(), LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new ArcaException("ARCA SOAP operation {$operation} returned an invalid XML response.");
        }

        $fault = $document->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Fault')->item(0);

        if ($fault !== null) {
            $faultText = $document->getElementsByTagName('faultstring')->item(0);
            $reason = trim($faultText === null ? '' : $faultText->textContent);
            $reason = preg_replace('/[A-Za-z0-9+\/_=-]{64,}/', '[redacted]', $reason) ?? '';
            $reason = substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $reason) ?? '', 0, 180);

            throw new ArcaException("ARCA SOAP operation {$operation} returned a SOAP fault".($reason === '' ? '.' : ": {$reason}"));
        }

        if (! $response->successful()) {
            throw new ArcaException("ARCA SOAP operation {$operation} returned HTTP {$response->status()}.");
        }

        $resultNode = $document->getElementsByTagName($operation.'Result')->item(0);

        if (! $resultNode instanceof DOMNode) {
            // WSAA's LoginCms response is a scalar rather than an operation Result object.
            $returnNode = $document->getElementsByTagName('loginCmsReturn')->item(0);

            return $returnNode === null ? [] : ['loginCmsReturn' => $returnNode->textContent];
        }

        $result = $this->nodeValue($resultNode);

        return is_array($result) ? $result : [];
    }

    /** @param array<string, mixed> $values */
    private function xmlElement(string $name, array $values, string $prefix = ''): string
    {
        $qualified = $prefix === '' ? $name : $prefix.':'.$name;

        if (array_key_exists('_value', $values)) {
            return '<'.$qualified.'>'.$this->escape((string) $values['_value']).'</'.$qualified.'>';
        }

        $children = '';

        foreach ($values as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $children .= $this->xmlElement((string) $key, is_array($item) ? $item : ['_value' => $item], $prefix);
                }

                continue;
            }

            if (is_array($value)) {
                $children .= $this->xmlElement((string) $key, $value, $prefix);
            } elseif ($value !== null) {
                $children .= '<'.$prefix.':'.(string) $key.'>'.$this->escape((string) $value).'</'.$prefix.':'.(string) $key.'>';
            }
        }

        return '<'.$qualified.'>'.$children.'</'.$qualified.'>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return array<string, mixed>|string|null */
    private function nodeValue(DOMNode $node): array|string|null
    {
        if (! $node->hasChildNodes()) {
            return null;
        }

        $elements = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $value = $this->nodeValue($child);
                $key = $child->localName;

                if (array_key_exists($key, $elements)) {
                    $elements[$key] = is_array($elements[$key]) && array_is_list($elements[$key])
                        ? [...$elements[$key], $value]
                        : [$elements[$key], $value];
                } else {
                    $elements[$key] = $value;
                }
            }
        }

        if ($elements !== []) {
            return $elements;
        }

        return trim($node->textContent);
    }
}
