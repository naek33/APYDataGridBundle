<?php

/*
 * This file is part of the DataGridBundle.
 *
 * (c) Stanislav Turza <sorien@mail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sorien\DataGridBundle\Column;

use Sorien\DataGridBundle\Column;

class Range extends Column
{
    private $values;

    public function drawFilter($gridId)
    {
        $data = $this->getFilterData();
        
        $result = '<div class="range-column-filter">';
        $result .= '<input class="first-filter" placeholder="From:" type="text" style="width:100%" value="'.$data['from'].'" name="'.$gridId.'['.$this->getId().'][filter][from]"/><br/>';
        $result .= '<input class="second-filter" placeholder="To:" type="text" style="width:100%" value="'.$data['to'].'" name="'.$gridId.'['.$this->getId().'][filter][to]"/><br/>';
        $result .= '</div>';
        return $result;
    }
}