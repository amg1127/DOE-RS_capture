#!/usr/bin/php5
<?php

define ('MIMETYPE_PDF', "application/pdf");

// Login e senha do site da CORAG => http://www.corag.com.br/
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
            $datas_baixar_doe[] = $matches[3] . "-" . $matches[2] . "-" . $matches[1];
        } else if (preg_match ("/^(\\d\\d\\d\\d)(\\d\\d)(\\d\\d)\$/", trim ($_SERVER['argv'][$i]), $matches)) {
            $datas_baixar_doe[] = $matches[1] . "-" . $matches[2] . "-" . $matches[3];
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
$saida = baixa_arquivo ("http://www.corag.com.br/");
if (empty ($saida)) {
    morre ("A pagina inicial da CORAG esta em branco!");
}

# Abrir a pagina de login da CORAG
$saida = baixa_arquivo ("http://www.corag.com.br/index.php?option=com_user&view=login&lang=en");
if (empty ($saida)) {
    morre ("A pagina de login da CORAG esta em branco!");
}

# Pegar campos ocultos do formulario de login
$logindom = new DOMDocument ("1.0", "utf-8");
$logindom->validateOnParse = true;
$logindom->recover = true;
$logindom->strictErrorChecking = false;
if (! @ $logindom->loadHTML ($saida)) {
    morre ("Impossivel analisar HTML da pagina de login da CORAG!");
}
$formul = $logindom->getElementById ("login");
if ($formul == null) {
    morre ("Impossivel localizar formulario de login da pagina da CORAG!");
}
$inputelements = $formul->getElementsByTagName ("input");
$submitotherdata = "&submit=Login";
for ($i = 0; $i < $inputelements->length; $i++) {
    $inputelement = $inputelements->item ($i);
    $eltype = $inputelement->attributes->getNamedItem ("type");
    if ($eltype != null) {
        if (strtolower (trim ($eltype->nodeValue)) == "hidden") {
            $elname = $inputelement->attributes->getNamedItem ("name");
            $elval = $inputelement->attributes->getNamedItem ("value");
            if ($elname != null && $elval != null) {
                if (! (empty ($elname->nodeValue) || empty ($elval->nodeValue))) {
                    $submitotherdata .= "&" . $elname->nodeValue . "=" . urlencode ($elval->nodeValue);
                }
            }
        }
    }
}

# Efetuar login na pagina da CORAG
$saida = baixa_arquivo ("http://www.corag.com.br/index.php?option=com_user&lang=en", "username=" . urlencode ($login) . "&passwd=" . urlencode ($senha) . $submitotherdata);
if (! preg_match ("/<span>Logout<\\/span>/is", $saida)) {
    morre ("Login falhou!");
}

# Acessar a pagina onde esta o DOE
$matches = array ();
$saida = baixa_arquivo ("http://www.corag.com.br/index.php?option=com_jornal&view=jornais&Itemid=119&lang=en");
if (! preg_match ("/<a\\s+class=['\"]?link_jornal['\"]?\\s+href=['\"]?(\\/index\\.php\\?option=com_jornal&[^'\">]+)['\"]?[^>]*>\\s*Di(&aacute;|a|á)rio\\s+Oficial\\s+do\\s+RS\\s*<\\/a>/is", $saida, $matches)) {
    morre ("Impossivel encontrar diario oficial do estado!");
}

$urlbasedoers = $matches[1];
if (count (explode ("&amp;", $urlbasedoers)) > 1) {
    $urlbasedoers = html_entity_decode ($urlbasedoers);
}
$urlbasedoers = "http://www.corag.com.br" . $urlbasedoers;

$saida = baixa_arquivo ($urlbasedoers);
if (! (stripos ($saida, "Diário Oficial do RS") && strpos ($saida, "Sumário da edição de"))) {
    morre ("Falha ao identificar pagina do Diario Oficial do RS");
}

if (substr ($urlbasedoers, -1) != "&") {
    $urlbasedoers .= "&";
}
$urlbasedoers = preg_replace ("/&data=\\d\\d\\d\\d-\\d\\d-\\d\\d&/", "&", $urlbasedoers) . "data=";

# Acessar a data especificada pelo usuario
foreach ($datas_baixar_doe as $data_doe) {
    $data_sepa = explode ('-', $data_doe);
    $tstmp_doe = implode ('', $data_sepa);
    $doe_output_file = $mydir . "/doe_" . $tstmp_doe . ".pdf";
    $tstmp_unix = mktime (0, 0, 0, $data_sepa[1], $data_sepa[2], $data_sepa[0]);
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
    $saida = baixa_arquivo ($urlbasedoers . urlencode($data_doe));

    if (! ($cnt_matches = preg_match_all ("/<div\\s+class=['\"]?item_pagina['\"]?\\s*>\\s*<a\\s+href=['\"]?(\\/index\\.php\\?option=com_jornal&[^'>\"]+)['\"]?\\s*>\\s*(\\d+)\\s*<\\/a>\\s*<\\/div>/is", $saida, $matches, PREG_SET_ORDER))) {
        morre ("Falha ao determinar as paginas disponiveis do DOE de '" . $data_doe . "'!");
    }
    $pdfjoin_args = '';
    $pg_primeiro = false;
    $pg_ultimo = false;
    $pgs = array ();
    foreach ($matches as $match) {
        if (! (stripos ($match[1], "&pagina=" . $match[2] . "&") || stripos ($match[1], "&amp;pagina=" . $match[2] . "&amp;"))) {
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
            $pgs[$pg_n] = array ($match[1], $match[2]);
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
                $n_fn = $pages_dir . "/doe_" . $tstmp_doe . "_pg" . $pgs[$pg_n][1] . ".pdf";
                if (file_exists ($n_fn)) {
                    if (testa_mimetype ($n_fn, MIMETYPE_PDF)) {
                        $pdfjoin_args .= ' ' . escapeshellarg ($n_fn);
                        continue;
                    }
                }
                $saida = baixa_arquivo ("http://www.corag.com.br" . $pgs[$pg_n][0]);
                if (! preg_match ("/<iframe\\s+src=['\"]?((http:\\/\\/www\\.corag\\.com\\.br\\/components\\/com_jornal\\/views\\/pagina\\/tmpl\\/)index\\.php)['\"]?\\s*[^>]*>/is", $saida, $iframedoe)) {
                    morre ("Impossivel abrir a pagina '" . $pgs[$pg_n][1] . "' do DOE de '" . $data_doe . "'!");
                }
                $saida = baixa_arquivo ($iframedoe[1]);
                if (! preg_match ("/<frame\\s+src=['\"]?superior\\.php['\"]?\\s+name=['\"]?superior['\"]?\\s+style=['\"]?z-index\\s*:\\s*\\d+\\s*;?\\s*['\"]?\\s*>/is", $saida)) {
                    morre ("Impossivel visualizar 'frameset' da pagina '" . $pgs[$pg_n][1] . "' do DOE de '" . $data_doe . "'!");
                }
                baixa_arquivo ($iframedoe[2] . "superior.php", false, true);
                // Os desenvolvedores da CORAG (ou PROCERGS) deixaram um bug na aplicacao: o PDF gerado possui caracteres estranhos no inicio do arquivo,
                // o que invalida a assinatura do PDF. Se houver essa assinatura invalida, remove-la.
                $arqsaida = file_get_contents ($output_file);
                if ($arqsaida === false) {
                    morre ("Impossivel analisar arquivo PDF baixado da pagina " . $pgs[$pg_n][1] . " do DOE de '" . $data_doe . "'!");
                }
                $cabec = substr ($arqsaida, 0, 58);
                if (preg_match ("/^\\/estatico\\/diario\\/doe\\/(img|pdf)\\/\\d\\d\\d\\d\\d\\d\\d\\d\\/doe\\d\\d\\d\\d\\d\\d\\d\\d_\\d\\d\\d\\.pdf%PDF-/is", $cabec)) {
                    $arqsaida = substr ($arqsaida, 53);
                    if (file_put_contents ($output_file, $arqsaida) !== strlen ($arqsaida)) {
                        morre ("Impossivel reescrever arquivo PDF baixado da pagina " . $pgs[$pg_n][1] . " do DOE de '" . $data_doe . "'!");
                    }
                }
                if (testa_mimetype ($output_file, MIMETYPE_PDF)) {
                    if (! copy ($output_file, $n_fn)) {
                        morre ("Impossivel salvar pagina '" . $pgs[$pg_n][1] . "' do DOE de '" . $data_doe . "' no computador!");
                    }
                    $pdfjoin_args .= ' ' . escapeshellarg ($n_fn);
                } else {
                    morre ("A pagina '" . $pgs[$pg_n][1] . "' do DOE de '" . $data_doe . "' nao eh um arquivo PDF!");
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
