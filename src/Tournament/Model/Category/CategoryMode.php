<?php

namespace Tournament\Model\Category;

enum CategoryMode: string
{
   case KO = 'ko';
   case Pool = 'pool';
   case Combined = 'combined';
}