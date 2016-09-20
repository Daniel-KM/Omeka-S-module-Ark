<?php

namespace Ark;

class Ark
{
    protected $naan;
    protected $name;
    protected $qualifier;

    public function __construct($naan, $name, $qualifier = null)
    {
        $this->naan = $naan;
        $this->name = $name;
        $this->qualifier = $qualifier;
    }

    public function setNaan($naan)
    {
        $this->naan = $naan;
    }

    public function getNaan()
    {
        return $this->naan;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setQualifier($qualifier)
    {
        $this->qualifier = $qualifier;
    }

    public function getQualifier()
    {
        return $this->qualifier;
    }

    public function asString()
    {
        $qualifier = $this->qualifier ? '/' . $this->qualifier : '';
        return sprintf('ark:/%s/%s%s', $this->naan, $this->name, $qualifier);
    }

    public function __toString()
    {
        return $this->asString();
    }
}
