<?php

return [
    // Ordered professional levels by cumulative number of closed skills across all PDPs of the user
    // You can add or reorder levels as needed. Threshold means: reach at least this many closed skills.
    'levels' => [
        [ 'key' => 'junior',   'title' => 'Junior',   'threshold' => 0 ],
        [ 'key' => 'junior_plus', 'title' => 'Junior+', 'threshold' => 9 ],
        [ 'key' => 'middle',   'title' => 'Middle',   'threshold' => 19 ],
        [ 'key' => 'senior',   'title' => 'Senior',   'threshold' => 35 ],
    ],
];
