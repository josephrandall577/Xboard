<?php
// Adds the Snell node type to the compiled Xboard admin bundle.
// The upstream admin source is not distributed with this fork. Keep the form
// definition here until a source-built distribution is available.
// Idempotent for this v2 form; refuse unknown/legacy Snell bundles.

$bundle = $argv[1] ?? null;
if (!$bundle || !is_file($bundle)) {
    fwrite(STDERR, "usage: php patch-admin-snell.php <path/to/index-*.js>\n");
    exit(1);
}

$s = file_get_contents($bundle);

if (str_contains($s, 'e.Snell="snell"')) {
    if (str_contains($s, 'xboard-snell v2')) {
        echo "already patched\n";
        exit(0);
    }
    fwrite(STDERR, "unknown or legacy Snell form; restore the pinned admin submodule before rebuilding\n");
    exit(1);
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
    // declare the snell schema/component before the registry that uses them
    '}),s5t=((e,t)=>({' =>
        '}),p_snellSchema=py({version:yy().default(6).transform(()=>6),mode:cy().default("default").transform(()=>"default")}),'
        . 'p_snellForm=({form:e,t:t})=>{'
        . 'return Q.jsxs(Q.Fragment,{children:['
        . 'Q.jsx("p",{className:"text-xs text-muted-foreground",children:"xboard-snell v2: PSK = user UUID"}),'
        . 'Q.jsx($y,{control:e.control,name:"version",render:({field:e})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{className:"text-[12px] font-mono text-foreground/80",children:"Version"}),Q.jsx(Yy,{children:Q.jsx(u8e,{...e,type:"number",className:"font-mono text-xs",value:6,readOnly:!0})})]})}),'
        . 'Q.jsx($y,{control:e.control,name:"mode",render:({field:e})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{className:"text-[12px] font-mono text-foreground/80",children:"Mode"}),Q.jsx(Yy,{children:Q.jsx(u8e,{...e,className:"font-mono text-xs",value:"default",readOnly:!0})})]})})'
        . ']})},s5t=((e,t)=>({',
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
$newBase = 'index-' . substr(hash_file('sha256', $bundle), 0, 10) . '.js';
if ($newBase !== $base) {
    $new = "$dir/$newBase";
    rename($bundle, $new);
    foreach ([dirname($dir) . '/index.html', dirname($dir) . '/manifest.json'] as $file) {
        if (is_file($file)) {
            file_put_contents($file, str_replace($base, $newBase, file_get_contents($file)));
        }
    }
    echo "cache-busted -> $newBase\n";
}
