import pytest
from ai_service.evaluations.datasets import load_golden_benchmark_cases
from ai_service.evaluations.runner import EvaluationRunner
from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.providers.mock import MockChatModel, MockEmbedder


@pytest.mark.asyncio
async def test_golden_benchmark_suite_passes_all_cases():
    """Verify that the full golden benchmark suite executes cleanly and satisfies quality gates."""
    synthesizer = AnswerSynthesizer(MockChatModel())
    runner = EvaluationRunner(synthesizer=synthesizer, embedder=MockEmbedder())

    cases = load_golden_benchmark_cases()
    summary = await runner.run_suite(cases)

    assert summary.total_cases == 5
    assert summary.failed_cases == 0
    assert summary.all_passed is True
    assert summary.avg_faithfulness >= 0.85
    assert summary.avg_context_recall >= 0.80