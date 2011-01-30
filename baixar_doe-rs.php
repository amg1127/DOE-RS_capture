#!/usr/bin/php5
<?php

define ('MIMETYPE_PDF', "application/pdf");

// Login e senha do site da CORAG => http://www.corag.rs.gov.br/
$login = "";
$senha = "";

$mydir = dirname (__FILE__);
$cookie_file = $mydir . '/cookies.txt';
$output_file = $mydir . '/tempfile.txt';
$pages_dir = $mydir . '/.doe_pages';
$pagenotfound_file = $mydir . '/page-not-found.pdf';

$wget = "wget -q --referer=\"Mozilla/5.0 (Windows; U; Windows NT 5.1; pt-BR; rv:1.9.2.3) Gecko/20100401 Firefox/3.6.3\" --load-cookies " . escapeshellarg ($cookie_file) . " --save-cookies " . escapeshellarg ($cookie_file) . " --keep-session-cookies --tries=1 --timeout=30 -O " . escapeshellarg ($output_file);
$maxtents = 5;

$datas_baixar_doe = array ();
if ($_SERVER['argc'] < 2) {
    aviso ("Nenhuma data de DOE foi especificada. Tentando baixar o de hoje...");
    $datas_baixar_doe[] = date ('d/m/Y');
} else {
    for ($i = 1; $i < $_SERVER['argc']; $i++) {
        if (preg_match ("/^(\\d\\d)\\s*\\/\\s*(\\d\\d)\\s*\\/\\s*(\\d\\d\\d\\d)\$/", trim ($_SERVER['argv'][$i]), $matches)) {
            $datas_baixar_doe[] = $matches[1] . "/" . $matches[2] . "/" . $matches[3];
        } else if (preg_match ("/^(\\d\\d\\d\\d)(\\d\\d)(\\d\\d)\$/", trim ($_SERVER['argv'][$i]), $matches)) {
            $datas_baixar_doe[] = $matches[3] . "/" . $matches[2] . "/" . $matches[1];
        } else {
            morre ("Data invalida no argumento em linha de comando #" . $i . ": '" . $_SERVER['argv'][$i] . "'!");
        }
    }
    $datas_baixar_doe = array_unique ($datas_baixar_doe);
}

if (! is_dir ($pages_dir)) {
    if (! mkdir ($pages_dir, 0750)) {
        morre ("Impossivel criar diretorio de hospedagem de paginas individuais do DOE!");
    }
}

function testa_mimetype ($fpath, $mime = false) {
    $retorno = true;
    $saida = "";
    if ($mime !== false) {
        $saida = trim (implode ("", executa_comando ("file --mime-type --brief " . escapeshellarg ($fpath))));
        if (empty ($saida)) {
            morre ("Comando 'file' nao retornou uma saida valida!");
        }
        if ($mime != $saida) {
            $retorno = false;
        }
    }
    if (! $retorno) {
        aviso ("Arquivo '" . basename ($fpath) . "' tem o mimetype '" . $saida . "', mas era desejado '" . $mime . "'. Apagando o arquivo...");
        unlink ($fpath);
    }
    return ($retorno);
}

function remove_temporarios () {
    global $cookie_file, $output_file;
    unlink ($cookie_file);
    unlink ($output_file);
}
register_shutdown_function ("remove_temporarios");

function morre ($msg) {
    echo (" **** " . $msg . " ****\n");
    exit (1);
}

function aviso ($msg) {
    echo (" ---- " . $msg . " ----\n");
}

function executa_comando ($comando) {
    for ($i = 1; $i <= 5; $i++) {
        // aviso ("Executando comando: '" . $comando . "'...");
        $saida = array ();
        exec ($comando, $saida, $retvar);
        if (! $retvar) {
            return ($saida);
        }
    }
    morre ("Impossivel executar comando: '" . $comando . "'!");
}

function baixa_arquivo ($url, $postdata = false, $dont_read = false) {
    global $wget, $output_file;
    static $referer = false;
    $cmd = $wget;
    if (! empty ($referer)) {
        $cmd .= " --referer=" . escapeshellarg ($referer);
    }
    $referer = $url;
    if (! empty ($postdata)) {
        $cmd .= " --post-data " . escapeshellarg ($postdata);
    }
    executa_comando ($cmd . " " . escapeshellarg ($url));
    if ($dont_read) {
        return (false);
    }
    $dados = file_get_contents ($output_file);
    if (empty ($dados)) {
        $dados = "";
    }
    return (trim ($dados));
}

# Abrir a pagina inicial da CORAG
$saida = baixa_arquivo ("http://www.corag.rs.gov.br/");
if (empty ($saida)) {
    morre ("A pagina inicial da CORAG esta em branco!");
}

# Efetuar login na pagina da CORAG
$saida = baixa_arquivo ("http://www.corag.rs.gov.br/logonsn.php", "edtUsuario=" . urlencode ($login) . "&edtSenha=" . urlencode ($senha) . "&btnLogar=Acessar");
if (! preg_match ("/<meta\\s+http-equiv=['\"]?refresh\\s*['\"]?\\s+content=['\"]?0\\s*;\\s*URL=\\/diario\\/diario\\.php\\s*['\"]?\\s*>/is", $saida)) {
    morre ("Login falhou!\n");
}
$saida = baixa_arquivo ("http://www.corag.rs.gov.br/diario/diario.php");
if (! preg_match ("/<a\\s+href=['\"]?\\.\\/jornal\\.php\\?jornal=doe['\"]?[^>]*>\\s*Di(&aacute;|a|á)rio\\s+Oficial\\s+do\\s+RS\\s*<\\/a>/is", $saida)) {
    morre ("Impossivel encontrar diario oficial do estado!");
}

# Acessar a pagina onde esta o DOE
$saida = baixa_arquivo ("http://www.corag.rs.gov.br/diario/jornal.php?jornal=doe");
if (! preg_match ("/<input\\s+type=['\"]?text['\"]?\\s+id=['\"]?edtData['\"]?\\s+name=['\"]?edtData['\"]?\\s+size=['\"]?\\d+['\"]?\\s+maxlength=['\"]?\\d+['\"]?\\s+value=['\"]?(|\\d+\\/\\d+\\/\\d+)['\"]?\\s*\\/?>/is", $saida)) {
    morre ("Falha ao encontrar o formulario de busca por data!");
}

# Acessar a data especificada pelo usuario
foreach ($datas_baixar_doe as $data_doe) {
    $data_sepa = explode ('/', $data_doe);
    $tstmp_doe = implode ('', array_reverse ($data_sepa));
    $doe_output_file = $mydir . "/doe_" . $tstmp_doe . ".pdf";
    $tstmp_unix = mktime (0, 0, 0, $data_sepa[1], $data_sepa[0], $data_sepa[2]);
    if ($tstmp_unix === false || $tstmp_unix < 0) {
        aviso ("Impossivel interpretar data '" . $data_doe . "'!");
    } else {
        $dia_sema = date ('w', $tstmp_unix);
        if ($dia_sema == 0) {
            aviso ("'" . $data_doe . "' cai em um domingo!");
        } else if ($dia_sema == 6) {
            aviso ("'" . $data_doe . "' cai em um sabado!");
        }
    }
    aviso ("Buscando DOE de '" . $data_doe . "' no site da CORAG...");
    $saida = baixa_arquivo ("http://www.corag.rs.gov.br/diario/jornal.php?jornal=doe", "SearchString=&sel_and_or=&edtData=" . urlencode($data_doe) . "&btn_pesquisar=Localizar");
    if (! ($cnt_matches = preg_match_all ("/<a\\s+href=['\"]?(\\.\\/controle\\.php\\?pg=\\d+)['\"]?\\s+class=['\"]?pagina['\"]?\\s*>\\s*(\\d+)\\s*<\\/a>/is", $saida, $matches, PREG_SET_ORDER))) {
        morre ("Falha ao determinar as paginas disponiveis do DOE de '" . $data_doe . "'!");
    }
    $pdfjoin_args = '';
    $pg_primeiro = false;
    $pg_ultimo = false;
    $pgs = array ();
    foreach ($matches as $match) {
        if (("./controle.php?pg=" . $match[2]) != $match[1]) {
            morre ("Inconsistencia no link '" . $match[0] . "' do DOE de '" . $data_doe . "'!");
        }
        $pg_n = intval ($match[2], 10);
        if ($pg_primeiro === false || $pg_primeiro > $pg_n) {
            $pg_primeiro = $pg_n;
        }
        if ($pg_ultimo === false || $pg_ultimo < $pg_n) {
            $pg_ultimo = $pg_n;
        }
        if (array_key_exists ($pg_n, $pgs)) {
            morre ("A pagina '" . $match[2] . "' do DOE de '" . $data_doe . "' esta repetida!");
        } else {
            $pgs[$pg_n] = $match[2];
        }
    }
    if ($pg_primeiro != 1) {
        morre ("Impossivel identificar a primeira pagina do DOE de '" . $data_doe . "'!");
    }
    $nao_precisa = true;
    for ($pg_n = 1; $pg_n <= $pg_ultimo; $pg_n++) {
        if (! array_key_exists ($pg_n, $pgs)) {
            $nao_precisa = false;
            break;
        }
    }
    if ($nao_precisa) {
        if (file_exists ($doe_output_file)) {
            if (! testa_mimetype ($doe_output_file, MIMETYPE_PDF)) {
                $nao_precisa = false;
            }
        } else {
            $nao_precisa = false;
        }
    }
    if (! $nao_precisa) {
        for ($pg_n = 1; $pg_n <= $pg_ultimo; $pg_n++) {
            if (array_key_exists ($pg_n, $pgs)) {
                $n_fn = $pages_dir . "/doe_" . $tstmp_doe . "_pg" . $pgs[$pg_n] . ".pdf";
                if (file_exists ($n_fn)) {
                    if (testa_mimetype ($n_fn, MIMETYPE_PDF)) {
                        $pdfjoin_args .= ' ' . escapeshellarg ($n_fn);
                        continue;
                    }
                }
                $saida = baixa_arquivo ("http://www.corag.rs.gov.br/diario/controle.php?pg=" . $pgs[$pg_n]);
                if (! preg_match ("/<meta\\s+http-equiv=['\"]?refresh['\"]?\\s+content=['\"]?0;\\s*url=\\.\\/index\\.php ['\"]?\\s*>/is", $saida)) {
                    morre ("Impossivel abrir a pagina '" . $pgs[$pg_n] . "' do DOE de '" . $data_doe . "'!");
                }
                $saida = baixa_arquivo ("http://www.corag.rs.gov.br/diario/index.php");
                if (! preg_match ("/<frame\\s+src=['\"]?\\/diario\\/superior.php['\"]?\\s+name=['\"]?superior['\"]?\\s*>/is", $saida)) {
                    morre ("Impossivel visualizar 'frameset' da pagina '" . $pgs[$pg_n] . "' do DOE de '" . $data_doe . "'!");
                }
                baixa_arquivo ("http://www.corag.rs.gov.br/diario/superior.php", false, true);
                if (testa_mimetype ($output_file, MIMETYPE_PDF)) {
                    if (! copy ($output_file, $n_fn)) {
                        morre ("Impossivel salvar pagina '" . $pgs[$pg_n] . "' do DOE de '" . $data_doe . "' no computador!");
                    }
                    $pdfjoin_args .= ' ' . escapeshellarg ($n_fn);
                } else {
                    morre ("A pagina '" . $pgs[$pg_n] . "' do DOE de '" . $data_doe . "' nao eh um arquivo PDF!");
                }
            } else {
                aviso ("A pagina #" . $pg_n . " do DOE de '" . $data_doe . "' nao foi publicada no site da CORAG!");
                if (! file_exists ($pagenotfound_file)) {
                    morre ("Impossivel verificar existencia do arquivo '" . $pagenotfound_file . "'!");
                }
                $pdfjoin_args .= ' ' . escapeshellarg ($pagenotfound_file);
            }
        }
        $pdfjoin_cmd = "pdfjoin --outfile " . escapeshellarg ($doe_output_file) . $pdfjoin_args;
        executa_comando ($pdfjoin_cmd);
    }
}

aviso ("Concluido.");
exit (0);
