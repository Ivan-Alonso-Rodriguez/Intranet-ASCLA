<?php
namespace ASCLA\Core\Domain;

/** Recognize only the legacy AI label; authored titles and stored sources stay intact. */
final class MicroeventTitle
{
    public static function display(string $title,array $meta): string
    {
        if (empty($meta['micro']) || empty($meta['chatham']) || !is_array($meta['agenda_ai']??null) || ($meta['agenda_ai']['title']??null)!==$title) { return $title; }
        $title=preg_replace('/^(?:participante|identidad reservada)(?=\h*·)/iu','Círculo ASCLA',$title)??$title;
        return preg_replace('/\h+(?:y|e)\h+(?:participante|identidad reservada)\h*$/iu','',$title)??$title;
    }
}
