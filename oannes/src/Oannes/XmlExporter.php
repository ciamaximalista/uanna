<?php

namespace Oannes;

use DOMDocument;

final class XmlExporter
{
    public function objectToXml(array $object): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('object');
        $doc->appendChild($root);

        $this->append($doc, $root, 'id', ActivityPub::objectId($object) ?? '');
        $this->append($doc, $root, 'type', ActivityPub::objectType($object));
        $this->append($doc, $root, 'published', ActivityPub::published($object));
        $this->append($doc, $root, 'attributedTo', ActivityPub::attributedTo($object) ?? '');
        $this->append($doc, $root, 'inReplyTo', ActivityPub::inReplyTo($object) ?? '');

        $content = $object['content'] ?? '';
        if (is_string($content)) {
            $this->append($doc, $root, 'content', $content);
        }

        return $doc->saveXML() ?: '';
    }

    private function append(DOMDocument $doc, \DOMElement $parent, string $name, string $value): void
    {
        $node = $doc->createElement($name);
        $node->appendChild($doc->createTextNode($value));
        $parent->appendChild($node);
    }
}

