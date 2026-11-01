"""Faqat local host, read-only GET uchun kichik controlled concurrency namunasi."""
import concurrent.futures
import json
import statistics
import time
import urllib.parse
import urllib.request


def run(base, path, requests=40, workers=4):
    parsed = urllib.parse.urlparse(base)
    if parsed.hostname not in ('127.0.0.1', 'localhost', 'smart-market-ai.test') or parsed.scheme not in ('http', 'https'):
        raise ValueError('Only explicit local hosts are supported')
    if path not in ('/up', '/login') or requests > 40 or workers > 4:
        raise ValueError('Bounded boot/login GET checks only')

    def request(_):
        started = time.perf_counter()
        with urllib.request.urlopen(base + path, timeout=10) as response:
            response.read(1048576)
            return response.status, (time.perf_counter() - started) * 1000

    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as executor:
        values = list(executor.map(request, range(requests)))
    timings = sorted(value[1] for value in values)
    return {'path': path, 'requests': requests, 'concurrency': workers,
            'http_200': sum(value[0] == 200 for value in values), 'median_ms': round(statistics.median(timings), 3),
            'p95_ms_local_sample': round(timings[int(len(timings) * .95) - 1], 3),
            'maximum_ms': round(max(timings), 3), 'production_capacity_verified': False}


if __name__ == '__main__':
    print(json.dumps([run('http://smart-market-ai.test', path) for path in ('/up', '/login')], indent=2))
