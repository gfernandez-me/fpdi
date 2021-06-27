<?php

/**
 * This file is part of FPDI
 *
 * @package   setasign\Fpdi
 * @copyright Copyright (c) 2020 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace setasign\Fpdi;

use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfNull;

/**
 * Class Fpdi
 *
 * This class let you import pages of existing PDF documents into a reusable structure for FPDF.
 */
class Fpdi extends FpdfTpl
{
    use FpdiTrait;

    protected $outlines = array();
    protected $outlineRoot;
    var $angle=0;

    /**
     * FPDI version
     *
     * @string
     */
    const VERSION = '2.3.6';

    protected function _enddoc()
    {
        parent::_enddoc();
        $this->cleanUp();
    }

    /**
     * Draws an imported page or a template onto the page or another template.
     *
     * Give only one of the size parameters (width, height) to calculate the other one automatically in view to the
     * aspect ratio.
     *
     * @param mixed $tpl The template id
     * @param float|int|array $x The abscissa of upper-left corner. Alternatively you could use an assoc array
     *                           with the keys "x", "y", "width", "height", "adjustPageSize".
     * @param float|int $y The ordinate of upper-left corner.
     * @param float|int|null $width The width.
     * @param float|int|null $height The height.
     * @param bool $adjustPageSize
     * @return array The size
     * @see Fpdi::getTemplateSize()
     */
    public function useTemplate($tpl, $x = 0, $y = 0, $width = null, $height = null, $adjustPageSize = false)
    {
        if (isset($this->importedPages[$tpl])) {
            $size = $this->useImportedPage($tpl, $x, $y, $width, $height, $adjustPageSize);
            if ($this->currentTemplateId !== null) {
                $this->templates[$this->currentTemplateId]['resources']['templates']['importedPages'][$tpl] = $tpl;
            }
            return $size;
        }

        return parent::useTemplate($tpl, $x, $y, $width, $height, $adjustPageSize);
    }

    /**
     * Get the size of an imported page or template.
     *
     * Give only one of the size parameters (width, height) to calculate the other one automatically in view to the
     * aspect ratio.
     *
     * @param mixed $tpl The template id
     * @param float|int|null $width The width.
     * @param float|int|null $height The height.
     * @return array|bool An array with following keys: width, height, 0 (=width), 1 (=height), orientation (L or P)
     */
    public function getTemplateSize($tpl, $width = null, $height = null)
    {
        $size = parent::getTemplateSize($tpl, $width, $height);
        if ($size === false) {
            return $this->getImportedPageSize($tpl, $width, $height);
        }

        return $size;
    }

    function Bookmark($txt, $isUTF8=false, $level=0, $y=0)
    {
        if(!$isUTF8)
            $txt = utf8_encode($txt);
        if($y==-1)
            $y = $this->GetY();
        $this->outlines[] = array('t'=>$txt, 'l'=>$level, 'y'=>($this->h-$y)*$this->k, 'p'=>$this->PageNo());
    }

    function _putbookmarks()
    {
        $nb = count($this->outlines);
        if($nb==0)
            return;
        $lru = array();
        $level = 0;
        foreach($this->outlines as $i=>$o)
        {
            if($o['l']>0)
            {
                $parent = $lru[$o['l']-1];
                // Set parent and last pointers
                $this->outlines[$i]['parent'] = $parent;
                $this->outlines[$parent]['last'] = $i;
                if($o['l']>$level)
                {
                    // Level increasing: set first pointer
                    $this->outlines[$parent]['first'] = $i;
                }
            }
            else
                $this->outlines[$i]['parent'] = $nb;
            if($o['l']<=$level && $i>0)
            {
                // Set prev and next pointers
                $prev = $lru[$o['l']];
                $this->outlines[$prev]['next'] = $i;
                $this->outlines[$i]['prev'] = $prev;
            }
            $lru[$o['l']] = $i;
            $level = $o['l'];
        }
        // Outline items
        $n = $this->n+1;
        foreach($this->outlines as $i=>$o)
        {
            $this->_newobj();
            $this->_put('<</Title '.$this->_textstring($o['t']));
            $this->_put('/Parent '.($n+$o['parent']).' 0 R');
            if(isset($o['prev']))
                $this->_put('/Prev '.($n+$o['prev']).' 0 R');
            if(isset($o['next']))
                $this->_put('/Next '.($n+$o['next']).' 0 R');
            if(isset($o['first']))
                $this->_put('/First '.($n+$o['first']).' 0 R');
            if(isset($o['last']))
                $this->_put('/Last '.($n+$o['last']).' 0 R');
            $this->_put(sprintf('/Dest [%d 0 R /XYZ 0 %.2F null]',$this->PageInfo[$o['p']]['n'],$o['y']));
            $this->_put('/Count 0>>');
            $this->_put('endobj');
        }
        // Outline root
        $this->_newobj();
        $this->outlineRoot = $this->n;
        $this->_put('<</Type /Outlines /First '.$n.' 0 R');
        $this->_put('/Last '.($n+$lru[0]).' 0 R>>');
        $this->_put('endobj');
    }

    function _putresources()
    {
        parent::_putresources();
        $this->_putbookmarks();
    }

    function _putcatalog()
    {
        parent::_putcatalog();
        if(count($this->outlines)>0)
        {
            $this->_put('/Outlines '.$this->outlineRoot.' 0 R');
            $this->_put('/PageMode /UseOutlines');
        }
    }
    
    /**
     * @inheritdoc
     * @throws CrossReferenceException
     * @throws PdfParserException
     */
    protected function _putimages()
    {
        $this->currentReaderId = null;
        parent::_putimages();

        foreach ($this->importedPages as $key => $pageData) {
            $this->_newobj();
            $this->importedPages[$key]['objectNumber'] = $this->n;
            $this->currentReaderId = $pageData['readerId'];
            $this->writePdfType($pageData['stream']);
            $this->_put('endobj');
        }

        foreach (\array_keys($this->readers) as $readerId) {
            $parser = $this->getPdfReader($readerId)->getParser();
            $this->currentReaderId = $readerId;

            while (($objectNumber = \array_pop($this->objectsToCopy[$readerId])) !== null) {
                try {
                    $object = $parser->getIndirectObject($objectNumber);
                } catch (CrossReferenceException $e) {
                    if ($e->getCode() === CrossReferenceException::OBJECT_NOT_FOUND) {
                        $object = PdfIndirectObject::create($objectNumber, 0, new PdfNull());
                    } else {
                        throw $e;
                    }
                }

                $this->writePdfType($object);
            }
        }

        $this->currentReaderId = null;
    }

    /**
     * @inheritdoc
     */
    protected function _putxobjectdict()
    {
        foreach ($this->importedPages as $key => $pageData) {
            $this->_put('/' . $pageData['id'] . ' ' . $pageData['objectNumber'] . ' 0 R');
        }

        parent::_putxobjectdict();
    }

    /**
     * @inheritdoc
     */
    protected function _put($s, $newLine = true)
    {
        if ($newLine) {
            $this->buffer .= $s . "\n";
        } else {
            $this->buffer .= $s;
        }
    }

    // ROTATE
    function Rotate($angle,$x=-1,$y=-1)
    {
        if($x==-1)
            $x=$this->x;
        if($y==-1)
            $y=$this->y;
        if($this->angle!=0)
            $this->_out('Q');
        $this->angle=$angle;
        if($angle!=0)
        {
            $angle*=M_PI/180;
            $c=cos($angle);
            $s=sin($angle);
            $cx=$x*$this->k;
            $cy=($this->h-$y)*$this->k;
            $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm',$c,$s,-$s,$c,$cx,$cy,-$cx,-$cy));
        }
    }

    function _endpage()
    {
        if($this->angle!=0)
        {
            $this->angle=0;
            $this->_out('Q');
        }
        parent::_endpage();
    }
}
