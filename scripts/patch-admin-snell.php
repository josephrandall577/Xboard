<?php
// Adds the Snell node type to the compiled Xboard admin bundle.
// Idempotent: running it twice is a no-op, and it leaves the bundle alone if
// a future upstream build already knows about snell.

$bundle = $argv[1] ?? null;
if (!$bundle || !is_file($bundle)) {
    fwrite(STDERR, "usage: php patch-admin-snell.php <path/to/index-*.js>\n");
    exit(1);
}

$s = file_get_contents($bundle);

if (str_contains($s, 'e.Snell="snell"')) {
    echo "already patched\n";
    exit(0);
}

$edits = [
    // protocol type enum
    'e.AnyTLS="anytls",e))' => 'e.AnyTLS="anytls",e.Snell="snell",e))',
    // type picker options
    '{type:"anytls",label:"AnyTLS"}' => '{type:"anytls",label:"AnyTLS"},{type:"snell",label:"Snell"}',
    // type badge colour
    'anytls:"#7E57C2"' => 'anytls:"#7E57C2",snell:"#E91E63"',
    // form schema + component registry
    'mieru:{schema:n5t(t),component:r5t}' => 'mieru:{schema:n5t(t),component:r5t},snell:{schema:p_snellSchema,component:p_snellForm}',
    // node list filter options
    '{value:iqt.Mieru,label:rqt.find(e=>e.type===iqt.Mieru)?.label,color:sqt[iqt.Mieru]}'
        => '{value:iqt.Mieru,label:rqt.find(e=>e.type===iqt.Mieru)?.label,color:sqt[iqt.Mieru]},{value:iqt.Snell,label:rqt.find(e=>e.type===iqt.Snell)?.label,color:sqt[iqt.Snell]}',
    // declare the snell schema/component inside the same comma sequence,
    // right after the registry initializer expression closes
    'py({})),o5t=({serverType:e,value:t,onChange:n})=>' =>
        'py({})),p_snellSchema=py({psk:cy().min(12).max(255).default(""),version:yy().default(6),mode:cy().default("default")}),'
        . 'p_snellForm=({form:e,t:t})=>{const n={default:"default",unshaped:"unshaped","unsafe-raw":"unsafe-raw"};'
        . 'return Q.jsxs(Q.Fragment,{children:['
        . 'Q.jsx($y,{control:e.control,name:"psk",render:({field:e})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{className:"text-[12px] font-mono text-foreground/80",children:"PSK"}),Q.jsx(Yy,{children:Q.jsx(u8e,{className:"font-mono text-xs",placeholder:"12-255 characters",...e})})]})}),'
        . 'Q.jsx($y,{control:e.control,name:"version",render:({field:e})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{className:"text-[12px] font-mono text-foreground/80",children:"Version"}),Q.jsx(Yy,{children:Q.jsx(u8e,{type:"number",className:"font-mono text-xs",...e,onChange:t=>e.onChange(Number(t.target.value))})})]})}),'
        . 'Q.jsx($y,{control:e.control,name:"mode",render:({field:e})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{className:"text-[12px] font-mono text-foreground/80",children:"Mode"}),Q.jsx(Yy,{children:Q.jsxs(yzt,{onValueChange:e.onChange,value:e.value,children:[Q.jsx(Czt,{className:"font-mono text-xs",children:Q.jsx(wzt,{placeholder:"default"})}),Q.jsx(Nzt,{hideWhenDetached:!0,children:Q.jsx(xzt,{children:Object.keys(n).map(e=>Q.jsx(Lzt,{value:e,children:n[e]},e))})})]})})]})})'
        . ']})},o5t=({serverType:e,value:t,onChange:n})=>',
];

foreach ($edits as $from => $to) {
    $count = substr_count($s, $from);
    if ($count !== 1) {
        fwrite(STDERR, "anchor not unique ($count): $from\n");
        exit(1);
    }
    $s = str_replace($from, $to, $s);
}

file_put_contents($bundle, $s);
echo "patched $bundle\n";

// Content-hash the bundle and repoint index.html/manifest.json at it, so
// CDN/browser caches cannot keep serving the pre-patch copy.
$dir = dirname($bundle);
$base = basename($bundle);
$hashed = preg_match('/^index-[0-9a-f]{10}\.js$/i', $base);
$newBase = 'index-' . substr(hash_file('sha256', $bundle), 0, 10) . '.js';
if (!$hashed && $newBase !== $base) {
    $new = "$dir/$newBase";
    rename($bundle, $new);
    foreach ([dirname($dir) . '/index.html', dirname($dir) . '/manifest.json'] as $file) {
        if (is_file($file)) {
            file_put_contents($file, str_replace($base, $newBase, file_get_contents($file)));
        }
    }
    echo "cache-busted -> $newBase\n";
}
