<?php

namespace MoMoPaymentChecker\Cache;

class File extends AbstractFactory
{
    /**
     * @var string
     */
    protected $storageDir;

    /**
     * @param string $storageDir
     * @return void
     */
    public function setStorageDir($storageDir)
    {
        $this->storageDir = $storageDir;
    }

    /**
     * @inheritDoc
     */
    public function has($messageId)
    {
        return \file_exists($this->getMessagePath($messageId));
    }

    /**
     * @inheritDoc
     */
    public function get($messageId)
    {
        if (!$this->has($messageId)) {
            throw new \InvalidArgumentException(\sprintf(
                'File (%s/%s.data) not exists',
                $this->storageDir,
                $messageId
            ));
        }

        $contents = \file_get_contents($this->getMessagePath($messageId));
        return \json_decode($contents, true);
    }

    /**
     * @inheritDoc
     */
    public function save($messageId, $data)
    {
        $path = $this->getMessagePath($messageId);
        $directory = \dirname($path);

        if (!\is_dir($directory)) {
            \mkdir($directory, 0755, true);
        }

        return \file_put_contents($path, \json_encode($data));
    }

    /**
     * @inheritDoc
     */
    public function delete($messageId)
    {
        if (!$this->has($messageId)) {
            throw new \InvalidArgumentException(\sprintf(
                'File (%s/%s.data) not exists',
                $this->storageDir,
                $messageId
            ));
        }

        \unlink($this->getMessagePath($messageId));
    }

    /**
     * @param string $messageId
     * @return string
     */
    protected function getMessagePath($messageId)
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . $messageId . '.data';
    }
}
