<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<url><loc>https://tutienditavenezolana.com/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>
@foreach($categories as $c)
<url><loc>https://tutienditavenezolana.com/categoria/{{ $c['slug'] }}</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>
@endforeach
@foreach($products as $p)
<url><loc>https://tutienditavenezolana.com/producto/{{ $p['slug'] }}</loc><lastmod>{{ $p['updated_at'] }}</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>
@endforeach
</urlset>
