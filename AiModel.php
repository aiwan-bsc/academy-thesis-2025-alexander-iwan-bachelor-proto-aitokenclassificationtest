<?php

interface AiModel
{
    public string $name {
        get;
    }
    public string $path {
        get;
    }

    public function getOutput(string $input) : array;

}