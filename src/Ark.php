<?php declare(strict_types=1);

namespace Ark;

class Ark
{
    /**
     * @var string
     */
    protected $naan;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string|null
     */
    protected $qualifier;

    /**
     * @param string $naan
     * @param string $name
     * @param string|null $qualifier
     */
    public function __construct($naan, $name, $qualifier = null)
    {
        $this->naan = $naan;
        $this->name = $name;
        $this->qualifier = $qualifier;
    }

    /**
     * @param string $naan
     * @return self
     */
    public function setNaan($naan)
    {
        $this->naan = $naan;
        return $this;
    }

    /**
     * @return string
     */
    public function getNaan()
    {
        return $this->naan;
    }

    /**
     * @param string $name
     * @return self
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param unknown $qualifier
     * @return self
     */
    public function setQualifier($qualifier)
    {
        $this->qualifier = $qualifier;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getQualifier()
    {
        return $this->qualifier;
    }

    /**
     * @return string
     */
    public function asString()
    {
        return sprintf('ark:/%s/%s%s', $this->naan, $this->name, $this->qualifier ? '/' . $this->qualifier : '');
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->asString();
    }
}
