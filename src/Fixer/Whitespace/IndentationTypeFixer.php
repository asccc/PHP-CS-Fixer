<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\Whitespace;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Fixer for rules defined in PSR2 ¶2.4.
 *
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 */
final class IndentationTypeFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
    /**
     * @var string
     */
    private $indent;

    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Code MUST use configured indentation type.',
            [
                new CodeSample("<?php\n\nif (true) {\n\techo 'Hello!';\n}\n"),
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        return 50;
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return $tokens->isAnyTokenKindsFound([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE]);
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        $this->indent = $this->whitespacesConfig->getIndent();
        
        $sourceIndent = $this->inferSourceIndent($tokens);

        foreach ($tokens as $index => $token) {
            if ($token->isComment()) {
                $tokens[$index] = $this->fixIndentInComment($tokens, $index, $sourceIndent);

                continue;
            }

            if ($token->isWhitespace()) {
                $tokens[$index] = $this->fixIndentToken($tokens, $index, $sourceIndent);

                continue;
            }
        }
    }
    
    /**
     * tries to infer indentation used in the source-file
     *
     * @param  Tokens $tokens
     * @return string         the inferred indentation-sequence
     */
    private function inferSourceIndent(Tokens $tokens) 
    {
        // find indentation in code
        $candidates = [];
        $lengths = [];
        $count = 0;
    
        foreach ($tokens as $token) {
            if ($token->isWhitespace()) {
                // get horizontal whitespace from this token
                $content = $token->getContent();
                
                if (!Preg::match('/\R(\h+)/', $content, $matches)) {
                    // not a new-line followed by horizontal space
                    continue;
                }
                
                $chars = $matches[1];
                $length = strlen($chars); 
                
                // check if the indent-length differs from
                // what we're already seen
                if (in_array($length, $lengths, true)) {
                    // ignore indent, same length already seen
                    continue;
                }
                
                $candidates[] = $chars;
                $lengths[] = $length;
                $count++;
                
                if ($count > 1) {
                    // we have two different indent tokens,
                    // it should be possible to infer the 
                    // source indentation now
                    break;
                }           
            }
        }
        
        if ($count === 0) {
            // not possible to infer, assume configured indent
            return $this->indent;
        }
        
        if ($count === 1) {
            // only one token, use this as indent
            return $candidates[0];
        }
        
        $candidate0 = $candidates[0];
        $candidate1 = $candidates[1];
        
        if (strpos($candidate0, "\t") !== false) {
            // source probably uses "\t" for indentation
            // check how many "\t" are used for one level (probably just one but you never know)
            return str_repeat("\t", abs(substr_count($candidate0, "\t") - substr_count($candidate1, "\t")));
        }
        
        // source probably uses spaces
        return str_repeat(' ', abs(substr_count($candidate0, ' ') - substr_count($candidate1, ' ')));
    }

    /**
     * @param Tokens $tokens
     * @param int    $index
     *
     * @return Token
     */
    private function fixIndentInComment(Tokens $tokens, $index, $sourceIndent)
    {        
        $indent = $this->indent;
        
        $content = Preg::replaceCallback(
            '/^(\h+)/m', 
            static function ($matches) use($indent, $sourceIndent) {
                return str_replace($sourceIndent, $indent, $matches[1]);
            },
            $tokens[$index]->getContent()
        );
        
        return new Token([$tokens[$index]->getId(), $content]);
    }

    /**
     * @param Tokens $tokens
     * @param int    $index
     *
     * @return Token
     */
    private function fixIndentToken(Tokens $tokens, $index, $sourceIndent)
    {
        $content = $tokens[$index]->getContent();
        $previousTokenHasTrailingLinebreak = false;

        // @TODO 3.0 this can be removed when we have a transformer for "T_OPEN_TAG" to "T_OPEN_TAG + T_WHITESPACE"
        if (false !== strpos($tokens[$index - 1]->getContent(), "\n")) {
            $content = "\n".$content;
            $previousTokenHasTrailingLinebreak = true;
        }

        $indent = $this->indent;
        
        $sourceUsesTabs = strpos($sourceIndent, "\t") !== false;
        $configWantsTabs = strpos($indent, "\t") !== false;
        
        if ($sourceUsesTabs && $configWantsTabs) {
            // don't bother ... it is not possible to
            // correct mixed spaces and tabs in this scenario
            return $tokens[$index];
        }
        
        $newContent = Preg::replaceCallback(
            '/(\R)(\h+)/', // find indent
            static function (array $matches) use ($indent, $sourceIndent) {
                // replace source-indent with configured indent
                return $matches[1].str_replace($sourceIndent, $indent, $matches[2]);
            },
            $content
        );

        if ($previousTokenHasTrailingLinebreak) {
            $newContent = substr($newContent, 1);
        }

        return new Token([T_WHITESPACE, $newContent]);
    }
}
