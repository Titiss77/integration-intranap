<?php

class SyncLogger {
    private $logFile;

    public function __construct($filename = 'sync_modifications.log') {
        // Définissez ici le chemin absolu vers votre dossier de logs
        // Assurez-vous que le dossier a les droits d'écriture (CHMOD 775 ou 777)
        $this->logFile = __DIR__ . '/' . $filename; 
    }

    private function write($level, $procedure, $message) {
        // Formatage : [2026-05-04 14:30:15] [ERROR] [API_FETCH] Message...
        $timestamp = date('Y-m-d H:i:s');
        $line = sprintf("[%s] [%-7s] [%-15s] %s" . PHP_EOL, $timestamp, $level, $procedure, $message);
        
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    public function info($procedure, $message) {
        $this->write('INFO', $procedure, $message);
    }

    public function success($procedure, $message) {
        $this->write('SUCCESS', $procedure, $message);
    }

    public function warning($procedure, $message) {
        $this->write('WARNING', $procedure, $message);
    }

    public function error($procedure, $message) {
        $this->write('ERROR', $procedure, $message);
    }
    
    public function separator() {
        file_put_contents($this->logFile, str_repeat("-", 80) . PHP_EOL, FILE_APPEND);
    }
}