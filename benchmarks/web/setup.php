<?php
// Generates the synthetic "framework" used by the web benchmark: N service
// classes, config files and routes, so bootstrap cost resembles a real
// framework (autoloading hundreds of classes, building a container, merging
// config, compiling routes). Output goes to app/generated/ (not committed).
$n = (int) ($argv[1] ?? 300);
$dir = __DIR__ . '/app/generated';
@mkdir("$dir/Services", 0777, true);
@mkdir("$dir/config", 0777, true);
for ($i = 0; $i < $n; $i++) {
    $dep = $i > 0 ? 'Service' . ($i - 1) : null;
    $ctor = $dep ? "public function __construct(private ?$dep \$dep = null) {}" : '';
    file_put_contents("$dir/Services/Service$i.php", <<<PHP
<?php
namespace Bench\Services;

final class Service$i
{
    public const NAME = 'service.$i';
    private array \$options = ['retries' => 3, 'timeout' => 1.5, 'tags' => ['a', 'b', 'c']];
    $ctor
    public function describe(): array { return ['name' => self::NAME, 'options' => \$this->options]; }
    public function compute(int \$x): int { return (\$x * $i + 7) % 1000003; }
}

PHP);
}
for ($c = 0; $c < 40; $c++) {
    $values = [];
    for ($k = 0; $k < 25; $k++) {
        $values["key$k"] = ['enabled' => (bool) ($k % 2), 'value' => "config-$c-$k", 'list' => range(0, 5)];
    }
    file_put_contents("$dir/config/section$c.php", '<?php return ' . var_export($values, true) . ";\n");
}
file_put_contents("$dir/COUNT", (string) $n);
echo "generated $n services and 40 config files in $dir\n";
