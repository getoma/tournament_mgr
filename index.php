<?php

require_once("lib/Tournament.php");

/* fetch input, without validation for this small demo */

if ($_SERVER["REQUEST_METHOD"] == "POST")
{
   $in_pool_size = $_POST["pool_size"];
   $in_num_rounds = $_POST["num_rounds"];
   $in_num_participants = $_POST["num_participants"];
}
else
{
   $in_pool_size = 3;
   $in_num_rounds = 5;
   $in_num_participants = 0;
}

$pool_size = $in_pool_size;

if( !$in_num_rounds && $in_num_participants )
{
   /* derive the number of rounds from the number of expected participants */
   $num_rounds = ceil(log($in_num_participants)/log(2));
}
else
{
   $num_rounds = $in_num_rounds;
}

/* derive the number of fights in the first non-pool round */
$num_start_fights = 2**($num_rounds-1);


if( !$in_num_participants )
{
   /* derive number of participants from number of rounds/fights + pool size */
   $num_participants = $num_start_fights * max(2,$pool_size);
}
else
{
   $num_participants = $in_num_participants;
}

$tournament = new Tournament();
$tournament->generateTournamentTree($num_participants, $pool_size );

/* generate the participants */
for( $i = 0; $i < $num_participants; ++$i )
{
   $p = new Participant( "Teilnehmer ".($i+1) );
   $tournament->addParticipant($p);
}

$tournament->shuffleParticipants();

?>
<!DOCTYPE HTML>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="css/index.css" />
  <title>Turnier-Demo</title>
</head>

<body>
<header>
<h1>Tournament Manager Demo</h1>
<p><a href="https://github.com/getoma/tournament_mgr">https://github.com/getoma/tournament_mgr</a></p>
</header>

<main>

<form method="post" accept-charset="UTF-8">
  <p class="number">
    <label for="inputPoolSize">Pool-Größe (1 = "keine Pools")</label>
    <input value="<?=$in_pool_size?>" min="1" step="1" id="inputPoolSize" type="number" name="pool_size">
  </p>

  <p class="number">
    <label for="inputNumRounds">Anzahl KO-Runden (0="je nach Anzahl Teilnehmer")</label>
    <input value="<?=$in_num_rounds?>" min="0" step="1" id="inputNumRounds" type="number" name="num_rounds">
  </p>

  <p class="number">
    <label for="inputNumParticipants">Anzahl Teilnehmer (0 = "je nach Anzahl Runden")</label>
    <input value="<?=$in_num_participants?>" min="0" step="1" id="inputNumParticipants" type="number" name="num_participants">
  </p>

  <div class="buttonbox"><input value="OK" type="submit"></div>
</form>

<? if($pool_size > 1): ?>
<section id="pool_list">
<h2>Pools</h2>

<ul class="pool_list">
<? foreach( $tournament->getPools() as $pool ): ?>
<li><p class="pool_name">Pool <?=$pool->getId()+1?></p>
	<ul class="participant_list">
		<? foreach( $pool->getParticipants() as $p): ?>
		<li><?=$p->getName()?></li>
   	<? endforeach ?>
	</ul>
	<p class="fight_list">Kämpfe:</p>
	<ul class="fight_list">
		<? foreach( $pool->getFights() as $f ): ?>
		<li><span class="fight_red"><?=$f->getPrevious(RED)->getName()?> vs
			<span class="fight_white"><?=$f->getPrevious(WHITE)->getName()?></span></span></li>
		<? endforeach ?>
	</ul>
</li>
<? endforeach ?>
</ul>
</section>
<? endif ?>

<section>
<h2>Turnier-Baum</h2>
<ul class="fight_tree">
<? foreach( $tournament->getFightsPerRound() as $r => $f_list ): ?>
   <li><span class="round_caption">Runde <?=$r?></span>
       <ul class="fight_round">
<?       foreach( $f_list as $f ): ?>
      	<li><span class="fight_name">Kampf <?=$f->getId()+1?></span>
      	    <? $pre_red = $f->getPrevious(RED);   ?>
      	    <? $pre_wh  = $f->getPrevious(WHITE); ?>
      	    <?php
      	       $gen_text = function( $pre, $color )
      	       {
      	          if( $pre instanceof Fight ) return( "Gewinner Kampf " . $pre->getId()+1 );
      	          elseif( $pre instanceof Pool ) return( (($color===RED)?"Erster":"Zweiter")." Pool " . $pre->getId()+1 );
      	          elseif( $pre instanceof Participant ) return( $pre->getName() );
      	          else return("Wildcard");
      	       }; ?>
      	    <span class="fight_pre"><?=$gen_text($pre_red, RED)?></span>
      	    vs.
      	    <span class="fight_pre"><?=$gen_text($pre_wh,WHITE) ?></span>
         </li>
<?       endforeach ?>
       </ul>
    </li>
<? endforeach ?>
</ul>
</section>

</main>

</body>
</html>