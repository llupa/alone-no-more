<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\World\Model\Position;

/**
 * Where the person asking is standing, if anywhere.
 *
 * The archives answer differently depending on who wants to know: "Milly Osworth is in Elwynn
 * Forest" is all that can be said from outside the game, while someone standing in Northshire
 * can be told she is a hundred and thirty paces north-east.
 *
 * An interface because Knowledge must not reach up into Roleplay to find out. It did: the
 * archive tools imported the roleplay layer's ambient bot context, which is backwards — the
 * lower layer asking the higher one where it is. Now the higher layer offers an answer and
 * Knowledge only knows there may be one.
 */
interface Viewpoint
{
    /**
     * Null when nobody is asking from inside the world — over MCP, for instance.
     */
    public function position(): ?Position;
}
